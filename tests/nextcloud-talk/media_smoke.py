#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Two synthetic WebRTC peers exercise real Janus SRTP flows in an isolated network."""
import asyncio
import json
import os
import random
import time
import uuid
from collections import defaultdict

import websockets
from aiortc import RTCPeerConnection, RTCSessionDescription, AudioStreamTrack, VideoStreamTrack

URL = os.environ.get("JANUS_WS_URL", "ws://janus-stage:8188/")
ROOM = random.randint(600000000, 900000000)
COUNTS = defaultdict(int)

def brief(msg):
    data = msg.get("plugindata", {}).get("data", {})
    return {
        "janus": msg.get("janus"), "room_event": data.get("videoroom"),
        "error": msg.get("error") or data.get("error"),
        "error_code": data.get("error_code"),
        "jsep": bool(msg.get("jsep")),
    }

class JanusWS:
    def __init__(self):
        self.ws = None
        self.session = None
        self.handle = None

    async def open(self):
        self.ws = await websockets.connect(
            URL, subprotocols=["janus-protocol"], open_timeout=12,
            ping_interval=15, max_size=4 * 1024 * 1024, proxy=None,
        )
        if self.ws.subprotocol != "janus-protocol":
            raise AssertionError("janus-protocol WebSocket handshake failed")
        response = await self.request("create")
        self.session = response["data"]["id"]
        response = await self.request("attach", plugin="janus.plugin.videoroom")
        self.handle = response["data"]["id"]

    async def request(self, janus, *, body=None, jsep=None, plugin=None, event=None, timeout=28):
        tx = uuid.uuid4().hex
        payload = {"janus": janus, "transaction": tx}
        if self.session and janus != "create":
            payload["session_id"] = self.session
        if self.handle and janus == "message":
            payload["handle_id"] = self.handle
        if body is not None:
            payload["body"] = body
        if plugin is not None:
            payload["plugin"] = plugin
        if jsep is not None:
            payload["jsep"] = jsep
        await self.ws.send(json.dumps(payload))
        end = time.monotonic() + timeout
        while True:
            remaining = end - time.monotonic()
            if remaining <= 0:
                raise TimeoutError(f"Janus {janus} event timed out")
            response = json.loads(await asyncio.wait_for(self.ws.recv(), remaining))
            if response.get("janus") == "error":
                raise RuntimeError(f"Janus protocol error {brief(response)}")
            data = response.get("plugindata", {}).get("data", {})
            if data.get("error_code"):
                raise RuntimeError(f"VideoRoom error {brief(response)}")
            if response.get("janus") == "ack":
                continue
            if event:
                if response.get("janus") in ("event", "success") and response.get("sender") == self.handle:
                    if data.get("videoroom") == event:
                        return response
                continue
            if response.get("transaction") == tx:
                return response

    async def call(self, body, *, jsep=None, event=None):
        return await self.request("message", body=body, jsep=jsep, event=event)

    async def close(self):
        if self.ws:
            try:
                if self.session:
                    await self.ws.send(json.dumps({
                        "janus": "destroy", "transaction":uuid.uuid4().hex,
                        "session_id":self.session,
                    }))
            except Exception:
                pass
            await self.ws.close()

async def consume(track):
    while COUNTS[track.kind] < 12:
        try:
            frame = await asyncio.wait_for(track.recv(), timeout=13)
        except Exception as exc:
            raise RuntimeError(f"Media track {track.kind} stopped: {type(exc).__name__}") from exc
        COUNTS[track.kind] += 1

async def main():
    pub, sub = JanusWS(), JanusWS()
    pc_pub, pc_sub = RTCPeerConnection(), RTCPeerConnection()
    tasks = []
    @pc_sub.on("track")
    def on_track(track):
        tasks.append(asyncio.create_task(consume(track)))

    try:
        await pub.open()
        print("JANUS_SESSION_OK", flush=True)
        r = await pub.call({
            "request": "create", "room": ROOM,
            "description": "Isolated automated SRTP smoke",
            "publishers": 3, "is_private": True,
            "audiocodec": "opus", "videocodec": "vp8",
            "permanent": False,
        })
        data = r.get("plugindata", {}).get("data", {})
        assert data.get("videoroom") == "created", brief(r)
        r = await pub.call({"request":"join", "ptype":"publisher",
                            "room":ROOM, "display":"isolated-publisher"}, event="joined")
        pub_id = r["plugindata"]["data"]["id"]
        print("VIDEOROOM_CREATE_AND_JOIN_OK", flush=True)

        pc_pub.addTrack(AudioStreamTrack())
        pc_pub.addTrack(VideoStreamTrack())
        await pc_pub.setLocalDescription(await pc_pub.createOffer())
        answer = await pub.call(
            {"request":"publish", "audio":True, "video":True},
            jsep={"type":"offer", "sdp":pc_pub.localDescription.sdp, "trickle":False},
            event="event",
        )
        assert answer.get("jsep",{}).get("type") == "answer", brief(answer)
        await pc_pub.setRemoteDescription(RTCSessionDescription(
            sdp=answer["jsep"]["sdp"], type="answer",
        ))
        print("PUBLISH_OFFER_ANSWER_OK", flush=True)
        deadline=time.monotonic()+20
        while pc_pub.connectionState not in ("connected","failed","closed") and time.monotonic()<deadline:
            await asyncio.sleep(0.25)
        print("PUBLISH_ICE_STATE",pc_pub.iceConnectionState,
              "DTLS_STATE",pc_pub.connectionState,flush=True)
        if pc_pub.connectionState!="connected":
            raise AssertionError("Publisher ICE and DTLS must establish before subscribing")

        await sub.open()
        subscribe = await sub.call(
            {"request":"join","ptype":"subscriber","room":ROOM,
             "streams":[{"feed":pub_id}],"use_msid":False},
            event="attached",
        )
        assert subscribe.get("jsep",{}).get("type") == "offer", brief(subscribe)
        await pc_sub.setRemoteDescription(RTCSessionDescription(
            sdp=subscribe["jsep"]["sdp"], type="offer",
        ))
        await pc_sub.setLocalDescription(await pc_sub.createAnswer())
        await sub.call({"request":"start","room":ROOM},
                       jsep={"type":"answer","sdp":pc_sub.localDescription.sdp, "trickle":False},
                       event="event")
        print("SUBSCRIBER_OFFER_ANSWER_OK",flush=True)

        deadline=time.monotonic()+22
        while time.monotonic()<deadline:
            if COUNTS["audio"]>=5 and COUNTS["video"]>=5 and pc_pub.connectionState=="connected" and pc_sub.connectionState=="connected":
                break
            await asyncio.sleep(0.25)
        stats=await pc_sub.getStats()
        streams=[{"type":v.type,"kind":getattr(v,"kind",""),"packetsReceived":getattr(v,"packetsReceived",0)}
                 for v in stats.values() if v.type=="inbound-rtp"]
        print("WEBRTC_RESULT",json.dumps({
            "publisher_connection":pc_pub.connectionState,
            "subscriber_connection":pc_sub.connectionState,
            "received_frames":{"audio":COUNTS["audio"],"video":COUNTS["video"]},
            "inbound_packets":streams,
        }), flush=True)
        assert pc_pub.connectionState=="connected", "publisher ICE/DTLS did not connect"
        assert pc_sub.connectionState=="connected", "subscriber ICE/DTLS did not connect"
        assert COUNTS["audio"]>=5 and COUNTS["video"]>=5, "insufficient real audio/video frames"
        assert all(s["packetsReceived"]>0 for s in streams), "RTP packet delivery failed"
        print("WEBRTC_AUDIO_VIDEO_MEDIA_PASS",flush=True)
    finally:
        for t in tasks:
            t.cancel()
        await pc_pub.close()
        await pc_sub.close()
        await sub.close()
        await pub.close()

if __name__ == "__main__":
    asyncio.run(asyncio.wait_for(main(), timeout=95))
