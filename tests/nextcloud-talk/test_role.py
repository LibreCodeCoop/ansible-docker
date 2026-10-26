# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Functional checks of Ansible rendering, Compose and HPB integration."""
import json
import os
import pwd
import re
import secrets
import shutil
import subprocess
import tempfile
import time
import unittest
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SUITE = ROOT / "tests" / "nextcloud-talk"
DIGEST = "7d9c1bd28bdbc7702de924ed8a676f5c1f11afa3abc888654d8d4fc8b78a00d2"
IMAGE = f"ghcr.io/librecodecoop/janus:1.4.3-r1@sha256:{DIGEST}"
COTURN_IMAGE = ("coturn/coturn:4.6.3-alpine@sha256:"
                "e2bca2f79a4269d7240de5872ab60a9305013ad37296d2acf14f9510874346be")


def run(*cmd, check=True, timeout=120, env=None):
    result = subprocess.run(
        [str(x) for x in cmd], cwd=ROOT, env=env, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        timeout=timeout, check=False,
    )
    if check and result.returncode:
        raise AssertionError(f"Command failed: {cmd[0:3]!r}\n{result.stdout[-5000:]}")
    return result


def variables(project, **overrides):
    args = {
        "talk_deploy_enabled": True,
        "talk_compose_up": False,
        "talk_project_dir": str(project),
        "talk_nextcloud_url": "https://cloud.example.invalid",
        "talk_signaling_hostname": "talk.example.invalid",
        "talk_letsencrypt_email": "contact@example.invalid",
        "talk_reverse_proxy_network": "",
        "talk_public_ipv4": "",
        "talk_signaling_config_owner": pwd.getpwuid(os.getuid()).pw_name,
        "talk_signaling_config_group": str(os.getgid()),
        "talk_coturn_config_owner": pwd.getpwuid(os.getuid()).pw_name,
        "talk_coturn_config_group": str(os.getgid()),
        "talk_session_hashkey": secrets.token_hex(32),
        "talk_session_blockkey": secrets.token_hex(16),
        "talk_internal_secret": secrets.token_hex(32),
        "talk_backend_secret": secrets.token_hex(32),
        "janus_rtp_port_start": 22600,
        "janus_rtp_port_end": 22650,
    }
    args.update(overrides)
    return args


def render(parent, config, expected_success=True, extra_env=None):
    project = Path(config["talk_project_dir"])
    parent.mkdir(parents=True, exist_ok=True)
    varfile = parent / f"vars-{uuid.uuid4().hex}.json"
    varfile.write_text(json.dumps(config))
    varfile.chmod(0o600)
    env = os.environ.copy()
    env["ANSIBLE_ROLES_PATH"] = str(ROOT / "roles")
    if extra_env:
        env.update(extra_env)
    try:
        result = run(
            "ansible-playbook", "-i", "localhost,", "-c", "local",
            SUITE / "render.yml", "--extra-vars", f"@{varfile}",
            env=env, check=False, timeout=120,
        )
    finally:
        varfile.unlink()
    if expected_success and result.returncode:
        raise AssertionError("Rendering failed:\n" + result.stdout[-3500:])
    if not expected_success and result.returncode == 0:
        raise AssertionError("Invalid configuration was accepted")
    if not expected_success:
        # A failed validation must not print the supplied secrets.
        for secret in (
            config["talk_session_hashkey"], config["talk_backend_secret"],
            config["talk_internal_secret"],
        ):
            if secret and secret in result.stdout:
                raise AssertionError("Secret was leaked by the validation logs")
    return result


def config_for(project):
    data = json.loads(run(
        "docker", "compose", "--project-directory", project,
        "-f", project / "docker-compose.yml", "config", "--format", "json",
    ).stdout)
    return data


class RenderedRoleTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.work = tempfile.TemporaryDirectory(prefix="talk-role-")
        cls.parent = Path(cls.work.name)
        cls.project = cls.parent / "rendered"
        cls.args = variables(cls.project)
        cls.first = render(cls.parent, cls.args)

    @classmethod
    def tearDownClass(cls):
        cls.work.cleanup()

    def test_image_and_network_policy(self):
        cfg = config_for(self.project)
        janus = cfg["services"]["janus"]
        self.assertEqual(janus["image"], IMAGE)
        self.assertEqual(cfg["services"]["spreedbackend"]["image"],
                         "ghcr.io/strukturag/nextcloud-spreed-signaling:24e899d@sha256:"
                         "a1924bbdae832f596568f11965a3a28695867d2557c1793d0919077d0c803c54")
        self.assertEqual(cfg["services"]["nats"]["image"],
                         "nats:2-alpine@sha256:"
                         "7ae34c25dfd6fe2e01c6483eabef20d796690fdcac6c4d94ca780a6cf9877fde")
        self.assertEqual(janus["command"], ["--full-trickle"])
        self.assertTrue(janus["read_only"])
        self.assertNotIn("network_mode", janus)
        self.assertEqual(set(janus["networks"]), {"hpb"})
        self.assertEqual(len(janus["ports"]), 51)
        self.assertTrue(all(p["protocol"] == "udp" for p in janus["ports"]))
        self.assertFalse(any(p.get("target") == 8188 for p in janus["ports"]))
        self.assertEqual(
            {v["target"].rsplit("/", 1)[-1] for v in janus["volumes"]},
            {"janus.jcfg", "janus.transport.websockets.jcfg", "janus.eventhandler.wsevh.jcfg"},
        )
        self.assertTrue(all(v["read_only"] for v in janus["volumes"]))
        # Compose v2 versions differ: explicit false can normalize to absent.
        self.assertTrue(all(
            not v.get("bind", {}).get("create_host_path", False)
            for v in janus["volumes"]
        ))
        source = (self.project / "docker-compose.yml").read_text()
        self.assertEqual(source.count("create_host_path: false"), 4)
        self.assertEqual(set(cfg["services"]), {"janus", "nats", "spreedbackend"})
        self.assertEqual(set(cfg["services"]["spreedbackend"]["networks"]), {"hpb"})
        self.assertFalse(cfg["services"]["spreedbackend"].get("ports"))
        self.assertEqual(cfg["services"]["spreedbackend"]["environment"]["CONFIG"],
                         "/config/server.conf")
        # Secrets belong in server.conf, not in process environment/Compose.
        for secret in ("talk_backend_secret", "talk_session_hashkey"):
            self.assertNotIn(self.args[secret], json.dumps(cfg))

    def test_generated_configs_are_consistent(self):
        path = self.project / "volumes/janus/config"
        self.assertIn("broadcast = true", (path / "janus.jcfg").read_text())
        self.assertIn('rtp_port_range = "22600-22650"', (path / "janus.jcfg").read_text())
        ws = (path / "janus.transport.websockets.jcfg").read_text()
        self.assertIn("ws_port = 8188", ws)
        ev = (path / "janus.eventhandler.wsevh.jcfg").read_text()
        for fragment in (
            "enabled = true", 'events = "handles,media,webrtc"',
            'backend = "ws://spreedbackend:8080/spreed"',
            'subprotocol = "janus-events"',
            "events_cap_on_reconnect = 500", "events_max_queue = 2000",
        ):
            self.assertIn(fragment, ev)
        secret_file = self.project / "server.conf"
        self.assertEqual(secret_file.stat().st_mode & 0o777, 0o640)
        conf = secret_file.read_text()
        self.assertEqual(conf.count("[backend]\n"), 1)
        self.assertIn("[nextcloud]\n", conf)
        self.assertIn("allowall = false", conf)
        self.assertIn("url = nats://nats:4222", conf)
        self.assertIn("url = ws://janus:8188/", conf)
        self.assertIn(self.args["talk_backend_secret"], conf)
        self.assertNotIn("[turn]", conf)

    def test_idempotent_render(self):
        result = render(self.parent, self.args)
        self.assertRegex(result.stdout, r"changed=0\s+unreachable=0\s+failed=0")

    def test_external_nat_mapping(self):
        project = self.parent / "nat"
        args = variables(project, talk_public_ipv4="192.0.2.10")
        render(self.parent, args)
        self.assertEqual(
            config_for(project)["services"]["janus"]["command"],
            ["--full-trickle", "--nat-1-1=192.0.2.10"],
        )

    def test_optional_managed_turn(self):
        project = self.parent / "coturn"
        args = variables(
            project, talk_public_ipv4="192.0.2.10",
            talk_manage_coturn=True,
            talk_coturn_image=COTURN_IMAGE,
            talk_turn_realm="turn.example.invalid",
            talk_turn_servers=["turn:turn.example.invalid:3478?transport=udp"],
            talk_turn_shared_secret=secrets.token_hex(32),
            talk_turn_api_key=secrets.token_hex(16),
        )
        render(self.parent, args)
        cfg = config_for(project)
        self.assertIn("coturn", cfg["services"])
        self.assertIn("[turn]\n", (project / "server.conf").read_text())
        self.assertEqual((project / "turnserver.conf").stat().st_mode & 0o777, 0o640)

    def test_role_disabled_by_default_does_nothing(self):
        project = self.parent / "disabled"
        args = {"talk_deploy_enabled": False, "talk_project_dir": str(project)}
        result = render(self.parent, args)
        self.assertEqual(result.returncode, 0)
        self.assertFalse(project.exists())

    def test_ini_section_injection_rejected(self):
        payloads = (
            {"talk_nats_url": "nats://nats:4222\n[backend]\nallowall = true"},
            {"talk_max_stream_bitrate": "1048576\n[backend]\nallowall = true"},
            {"talk_max_screen_bitrate": "2097152\n[backend]\nallowall = true"},
            {"talk_turn_api_key": "A" * 32 + "\n[backend]\nallowall = true",
             "talk_turn_servers": ["turn:example.com:3478?transport=udp"],
             "talk_turn_shared_secret": secrets.token_hex(32)},
            {"talk_turn_shared_secret": "B" * 40 + "\n[backend]\nallowall = true",
             "talk_turn_servers": ["turn:example.com:3478?transport=udp"],
             "talk_turn_api_key": secrets.token_hex(16)},
            {"talk_turn_servers": ["turn:example.com:3478?transport=udp\n[backend]\nallowall = true"],
             "talk_turn_api_key": secrets.token_hex(16),
             "talk_turn_shared_secret": secrets.token_hex(32)},
        )
        for index, payload in enumerate(payloads):
            with self.subTest(index=index):
                cfg = variables(self.parent / f"ini-inject-{index}", **payload)
                render(self.parent, cfg, expected_success=False)

    def test_invalid_path_and_service_port_rejected(self):
        render(self.parent, variables(self.parent / "bad-path", talk_project_dir="/tmp/../etc/talk"),
               expected_success=False)
        render(self.parent, variables(self.parent / "bad-service-port", talk_signaling_port=70000),
               expected_success=False)

    def test_invalid_turn_realm_and_nats_port_rejected(self):
        render(self.parent, variables(self.parent / "invalid-nats-port",
               talk_nats_url="nats://nats:99999"), expected_success=False)
        args = variables(self.parent / "invalid-turn-realm", talk_manage_coturn=True,
                         talk_coturn_image=COTURN_IMAGE,
                         talk_turn_realm="example.invalid\nno-auth", talk_public_ipv4="192.0.2.10",
                         talk_turn_servers=["turn:turn.example.invalid:3478?transport=udp"],
                         talk_turn_api_key=secrets.token_hex(16),
                         talk_turn_shared_secret=secrets.token_hex(32))
        render(self.parent, args, expected_success=False)

    def test_mutable_signaling_and_nats_images_rejected(self):
        for key, image in (
            ("spreed_image", "ghcr.io/strukturag/nextcloud-spreed-signaling:24e899d"),
            ("nats_image", "nats:2-alpine"),
        ):
            with self.subTest(image=image):
                render(self.parent, variables(self.parent / f"unpinned-{key}", **{key: image}),
                       expected_success=False)

    def test_deploy_recreates_only_when_rendered_configuration_changes(self):
        project = self.parent / "reconcile"
        bin_dir = self.parent / "mock-bin"
        bin_dir.mkdir(exist_ok=True)
        log = self.parent / "compose-commands.log"
        executable = shutil.which("docker")
        if not executable:
            self.skipTest("Docker is required")
        wrapper = bin_dir / "docker"
        wrapper.write_text(
            f"""#!/bin/sh
for arg in "$@"; do
    if [ "$arg" = up ]; then
        printf '%s\\n' "$*" >> "$TALK_COMPOSE_AUDIT_LOG"
        printf 'Container test Recreated\\n' >&2
        exit 0
    fi
done
exec "{executable}" "$@"
"""
        )
        wrapper.chmod(0o755)
        extra_env = {
            "PATH": str(bin_dir) + os.pathsep + os.environ["PATH"],
            "TALK_COMPOSE_AUDIT_LOG": str(log),
        }
        config = variables(project, talk_compose_up=True)
        render(self.parent, config, extra_env=extra_env)
        render(self.parent, config, extra_env=extra_env)
        config["janus_event_queue_limit"] = 3000
        render(self.parent, config, extra_env=extra_env)
        render(self.parent, config, extra_env=extra_env)
        calls = log.read_text().splitlines()
        self.assertEqual(len(calls), 4)
        self.assertEqual(["--force-recreate" in call for call in calls],
                         [True, False, True, False])

    def test_invalid_ip_rejected(self):
        args = variables(self.parent / "invalid-ip", talk_public_ipv4="999.999.999.999")
        render(self.parent, args, expected_success=False)

    def test_image_entrypoint_is_not_repeated(self):
        cfg = config_for(self.project)
        janus = cfg["services"]["janus"]
        # Docker Compose normalizes an absent entrypoint to a null field.
        self.assertIsNone(janus.get("entrypoint"))
        self.assertNotIn("/usr/local/bin/janus", " ".join(janus["command"]))

    def test_missing_secrets_rejected(self):
        args = variables(self.parent / "invalid-secrets", talk_backend_secret="")
        render(self.parent, args, expected_success=False)

    def test_unpinned_image_rejected(self):
        args = variables(self.parent / "invalid-image", janus_image="ghcr.io/librecodecoop/janus:latest")
        render(self.parent, args, expected_success=False)

    def test_invalid_ports_rejected(self):
        args = variables(self.parent / "invalid-port", janus_rtp_port_end=66000)
        render(self.parent, args, expected_success=False)

    def test_missing_nat_dependency_rejected_for_turn(self):
        args = variables(self.parent / "invalid-coturn", talk_manage_coturn=True)
        render(self.parent, args, expected_success=False)


@unittest.skipUnless(os.environ.get("RUN_HPB_INTEGRATION") == "1", "Integration opt-in")
class ContainerIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.work = tempfile.TemporaryDirectory(prefix="talk-role-media-")
        cls.parent = Path(cls.work.name)
        cls.project = cls.parent / "rendered"
        cls.project_name = f"talkci{uuid.uuid4().hex[:10]}"
        cls.cfg = variables(cls.project)
        render(cls.parent, cls.cfg)
        cls.compose = [
            "docker", "compose", "-p", cls.project_name,
            "--project-directory", cls.project,
            "-f", cls.project / "docker-compose.yml",
        ]
        # The pinned signaling image drops privileges to UID 850.
        # Only root in a disposable container changes the group of a test file;
        # production defaults already assign root:850, 0640.
        run(
            "docker", "run", "--rm", "--user", "0:0",
            "--entrypoint", "chgrp",
            "-v", f"{cls.project}:/hpb",
            "busybox:1.37", "850", "/hpb/server.conf", timeout=100,
        )
        try:
            run(*cls.compose, "up", "-d", "--no-build", "--pull", "missing",
                "janus", "nats", "spreedbackend", timeout=300)
        except Exception:
            run(*cls.compose, "down", "--volumes", "--remove-orphans", check=False)
            cls.work.cleanup()
            raise

    @classmethod
    def tearDownClass(cls):
        if hasattr(cls, "compose"):
            run(*cls.compose, "down", "--volumes", "--remove-orphans",
                check=False, timeout=120)
        if hasattr(cls, "work"):
            cls.work.cleanup()

    @classmethod
    def wait_for(cls, service, markers, timeout=95):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            logs = run(*cls.compose, "logs", "--no-color", service).stdout
            if all(fragment in logs for fragment in markers):
                return logs
            time.sleep(2)
        raise AssertionError(
            f"Timed out waiting for {service}: {markers!r}\n" + logs[-3500:]
        )

    def test_optional_coturn_starts_with_readable_protected_config(self):
        with tempfile.TemporaryDirectory(prefix="talk-role-turn-") as temp:
            project = Path(temp) / "turn"
            name = f"talkturn{uuid.uuid4().hex[:10]}"
            container_name = name + "-coturn"
            cfg = variables(
                project, talk_public_ipv4="192.0.2.10", talk_manage_coturn=True,
                talk_coturn_image=COTURN_IMAGE, talk_turn_realm="turn.example.invalid",
                talk_turn_servers=["turn:turn.example.invalid:3478?transport=udp"],
                talk_turn_api_key=secrets.token_hex(16),
                talk_turn_shared_secret=secrets.token_hex(32),
            )
            render(Path(temp), cfg)
            # Host runner is not root: isolate chgrp to a disposable test file.
            # Official image uses GID 65533 (nogroup), not host's nogroup ID.
            run("docker", "run", "--rm", "--user", "0:0", "--entrypoint", "chgrp",
                "-v", f"{project}:/hpb", "busybox:1.37", "65533",
                "/hpb/turnserver.conf", timeout=100)
            compose = ["docker", "compose", "-p", name,
                       "-f", project / "docker-compose.yml"]
            try:
                proof = run(*compose, "run", "--no-deps", "--rm",
                            "--entrypoint", "/bin/sh", "coturn", "-c",
                            "test -r /etc/turnserver.conf && echo COTURN_CONFIG_READABLE",
                            timeout=150)
                self.assertIn("COTURN_CONFIG_READABLE", proof.stdout)
                run(*compose, "run", "--no-deps", "-d", "--name", container_name,
                    "coturn", timeout=180)
                time.sleep(3)
                status = run("docker", "inspect", "--format", "{{.State.Running}}",
                             container_name).stdout.strip()
                self.assertEqual(status, "true")
            finally:
                run("docker", "rm", "-f", container_name, check=False)
                run(*compose, "down", "--volumes", "--remove-orphans", check=False)

    def test_signaling_janus_events_and_media(self):
        self.wait_for(
            "janus",
            ["JANUS VideoRoom plugin initialized", "WebSocketsEventHandler connected"],
        )
        self.wait_for(
            "spreedbackend",
            ["Connected to Janus WebRTC Server 1.4.3", "Processing Janus events from"],
        )
        tag = f"talk-role-smoke:{self.project_name}"
        try:
            run("docker", "build", "-q", "-t", tag, "-f",
                SUITE / "Dockerfile.media", SUITE, timeout=300)
            media = run(
                "docker", "run", "--rm", "--network", f"{self.project_name}_hpb",
                "--security-opt", "no-new-privileges", "--read-only", "--tmpfs", "/tmp",
                "-e", "JANUS_WS_URL=ws://janus:8188/",
                tag, timeout=125,
            )
            self.assertIn("WEBRTC_AUDIO_VIDEO_MEDIA_PASS", media.stdout)
            prior_events = run(*self.compose, "logs", "--no-color", "janus").stdout.count(
                "WebSocketsEventHandler connected"
            )
            prior_signaling = run(
                *self.compose, "logs", "--no-color", "spreedbackend"
            ).stdout.count("Connected to Janus WebRTC Server 1.4.3")
            run(*self.compose, "restart", "spreedbackend", timeout=40)
            deadline = time.monotonic() + 55
            while time.monotonic() < deadline:
                janus_log = run(*self.compose, "logs", "--no-color", "janus").stdout
                signaling_log = run(
                    *self.compose, "logs", "--no-color", "spreedbackend"
                ).stdout
                if (
                    janus_log.count("WebSocketsEventHandler connected") > prior_events
                    and signaling_log.count("Connected to Janus WebRTC Server 1.4.3")
                    > prior_signaling
                ):
                    break
                time.sleep(2)
            else:
                raise AssertionError("wsevh or MCU did not reconnect after signaling restart")
            repeated = run(
                "docker", "run", "--rm", "--network", f"{self.project_name}_hpb",
                "--security-opt", "no-new-privileges", "--read-only", "--tmpfs", "/tmp",
                "-e", "JANUS_WS_URL=ws://janus:8188/",
                tag, timeout=125,
            )
            self.assertIn("WEBRTC_AUDIO_VIDEO_MEDIA_PASS", repeated.stdout)
        finally:
            run("docker", "image", "rm", tag, check=False, timeout=60)


if __name__ == "__main__":
    unittest.main()
