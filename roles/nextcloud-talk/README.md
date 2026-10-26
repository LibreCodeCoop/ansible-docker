# Nextcloud Talk high-performance backend (HPB)

This role renders and can deploy Janus, standalone signaling and NATS using
Docker Compose v2. It **does not install Docker** and does **nothing** unless
`talk_deploy_enabled: true` is explicitly supplied.

## Official Janus image

- [Published GHCR package](https://github.com/LibreCodeCoop/janus-docker/pkgs/container/janus): `ghcr.io/librecodecoop/janus`
- [Source repository and Dockerfile](https://github.com/LibreCodeCoop/janus-docker)
- [GitHub Releases](https://github.com/LibreCodeCoop/janus-docker/releases)

The default is the **dated, validated 25 October 2026** version
`1.4.3-r1` pinned to OCI digest. Review current releases before deploying;
never replace this with `latest` or rely only on a mutable tag. Signaling and
NATS images are also pinned to their validated OCI digests.

## Prerequisites

- Linux with a modern Docker Engine and `docker compose` v2, installed
  separately, plus Ansible Core and privilege escalation for deployment.
- A public HTTPS reverse proxy with external Docker network
  `reverse-proxy` by default. Adapt `talk_reverse_proxy_network` to its
  real network name (or set it empty for completely internal testing).
- A real Nextcloud backend URL, signaling public hostname, and
  externally reachable UDP media ports.
- TURN configured separately (Eturnal recommended); optional managed Coturn
  is available for non-TLS setups.

An example playbook:

```yaml
- name: Deploy Nextcloud Talk HPB
  hosts: hpb
  become: true
  roles:
    - role: nextcloud-talk
  vars:
    talk_deploy_enabled: true
    talk_project_dir: /opt/nextcloud-talk-hpb
    talk_nextcloud_url: https://cloud.example.com
    talk_signaling_hostname: talk.example.com
    talk_letsencrypt_email: contact@example.com
    talk_public_ipv4: 203.0.113.10
    talk_reverse_proxy_network: reverse-proxy
    janus_rtp_port_start: 20000
    janus_rtp_port_end: 20100
```

Use your actual public IPv4; `203.0.113.10` is a documentation-only address.
Set `talk_public_ipv4` when the Janus Docker bridge is behind NAT; leaving it
empty means no `--nat-1-1` flag (useful for isolated tests or routable setups).

## Secrets

Supply these required values **only through an encrypted Ansible Vault file
or another approved secret manager**, never in Git:

| Variable | Recommended generation |
| --- | --- |
| `talk_session_hashkey` | `openssl rand -hex 32` |
| `talk_session_blockkey` | `openssl rand -hex 16` |
| `talk_internal_secret` | `openssl rand -hex 32` |
| `talk_backend_secret` | `openssl rand -hex 32` |

The backend secret must match Nextcloud's Talk signaling configuration. If
TURN credentials are configured, provide `talk_turn_servers` (list),
`talk_turn_shared_secret`, and `talk_turn_api_key`, matching the TURN provider.

**Security:** previous versions of the role tracked literal secret-looking
strings in a signaling template. Audit whether those values were ever used,
and rotate them wherever they were deployed. Removing them from the latest
source does not revoke credentials found in Git history.

The signaling image runs its worker as UID/GID 850. By default, Ansible
writes `server.conf` root:850 with mode `0640`. The Ansible role suppresses
logging when validating or rendering secrets. When using a different
signaling image, review its UID/GID before changing
`talk_signaling_config_group`.

## Networking and runtime

The role creates a private `hpb` Docker network shared by the three main
services. Janus exposes UDP media ports only; its WebSockets API
(`ws://janus:8188/`), NATS and Janus administration are not exposed to
the public Internet. The signaling container joins the existing external
proxy network when configured.

- `janus.jcfg` sets media port range and `events.broadcast = true`.
- `janus.transport.websockets.jcfg` enables WebSocket API without WSS/admin.
- `janus.eventhandler.wsevh.jcfg` connects to
  `ws://spreedbackend:8080/spreed` with `janus-events`; its subscribed
  events and queue/replay bounds are explicit.
- `server.conf` uses a single allowed Nextcloud backend, `allowall=false`,
  valid NATS and MCU URLs, and secrets from Ansible Vault.
- The published Janus image already sets
  `ENTRYPOINT /usr/local/bin/janus`: `command` contains flags only.
- All configuration bind mounts are read-only and deny automatic host-path
  creation, preventing a missing `.jcfg` file from becoming a directory.

The optional `talk_manage_coturn` is disabled by default. It requires
`talk_coturn_image`, `talk_turn_realm`, TURN credentials, public IPv4 and
relay ports; it is a non-TLS example, not automatic TURNS certificate
provisioning. For real TLS TURN use an independently configured service.
The tested `coturn/coturn:4.6.3-alpine` image runs as UID 65534/GID 65533;
`turnserver.conf` defaults to `root:65533` mode `0640` so the secret stays
private while remaining readable by Coturn. Review these IDs when changing
images. Running a container alone does not prove authenticated relay works;
validate TURN from separate networks before production use.

## Apply, test, rollback

Set `talk_compose_up: false` to render and validate files without running
containers, even with `talk_deploy_enabled: true`. When enabled, the role
runs `docker compose up -d --no-build --pull missing` and never builds
an image in production.
Updates to mounted Janus, signaling or TURN configuration recreate the HPB
containers so settings take effect. Schedule these changes when calls are
not active. An unchanged rendering does not force a restart; Compose
still reconciles missing services.

Deploy this role into a dedicated Compose project, not a shared stack. It
will not automatically delete old containers or secrets. Disabling managed
Coturn does **not** stop an orphaned Coturn container from an earlier
configuration; plan explicit removal and protected secret cleanup after
verifying that existing calls do not depend on that service. Do not apply
`--remove-orphans` to unrelated shared projects.

Generated files under `talk_project_dir`:

```text
docker-compose.yml
server.conf
volumes/janus/config/janus.jcfg
volumes/janus/config/janus.transport.websockets.jcfg
volumes/janus/config/janus.eventhandler.wsevh.jcfg
turnserver.conf  # only when talk_manage_coturn is enabled
```

After deployment, inspect:

```bash
cd /opt/nextcloud-talk-hpb
docker compose config --quiet
docker compose ps
docker compose logs --tail=100 janus spreedbackend
```

Confirm Janus/VideoRoom startup, a signaling connection to Janus and
delivery of `wsevh` events. Before upgrades, preserve the previous
`docker-compose.yml`, `.jcfg` files and protected `server.conf`, as well as
the previous image digest. When rolling back, restore all these files and
run `docker compose up -d --no-deps --force-recreate janus`, then validate
signaling. Only update during a maintenance window with no active calls.
Synthetic tests do **not** replace testing audio, video, screenshare, TURN
fallback and reconnection between real users in different networks.

## Automated regression tests

`tests/nextcloud-talk` validates settings, pinned release images,
rendered configs, Compose semantics, idempotency, secret handling and
negative cases. In CI, an isolated Janus/signaling/NATS deployment
exercises real synthetic WebRTC audio/video with two clients and reconnects
signaling. The optional Coturn test verifies actual non-root access to
its protected configuration and successful container startup. No production
host or credentials are used.
