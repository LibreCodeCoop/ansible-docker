# Nginx Proxy role

This role deploys the shared Nginx proxy Compose project and its Docker network.
It does not install Docker Engine or the legacy Compose v1 executable. Provision
the reusable Docker Engine role before including this role.

## Configuration

- nginx_proxy_project_path: host directory for the proxy checkout.
- nginx_proxy_network_name: Docker network (default: reverse-proxy).
- nginx_proxy_repository_url: reviewed Nginx Proxy project Git URL.
- nginx_proxy_repository_ref: reviewed, pinned Git commit (default corresponds
  to upstream main on 2026-05-04).
- nginx_proxy_update_repository: explicitly synchronize existing checkout
  (default false). Existing local repository changes will not be force-reset.

To deploy on a fresh, supported host:

    - hosts: proxy_hosts
      become: true
      roles:
        - docker
        - nginx-proxy

The role creates the network and reconciles the Compose project once per run.
It does not force pull or restart unchanged services. An existing checkout
is preserved unless repository synchronization is explicitly requested.

## Operational review

Before deployment, inspect the target's Docker networks, existing project
checkout, image tags, volumes, certificates and reverse-proxy services.
Validate a disposable VM first and plan maintenance with an explicit per-host
approval. For rollback, restore the previous Git ref and re-apply the reviewed
Compose project; preserve its volumes and certificates. Container runtime
tests, network integration and rollback are not yet automated.
