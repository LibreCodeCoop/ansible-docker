# Docker Engine role

Installs Docker Engine, containerd, Buildx and the Compose v2 CLI plugin from
Docker's upstream signed repositories. No Compose v1/Python SDK is installed.
The role does not deploy application containers.

## Supported targets

Debian and Ubuntu, or RHEL/Rocky/AlmaLinux, on x86_64 and aarch64.
The target needs a supported distribution release, systemd and outbound HTTPS.
Ansible Core with `ansible.builtin.deb822_repository` is required (2.15+).
Apply with privilege escalation (`become: true`). Fedora, CentOS Stream and
other operating systems are not claimed as supported.

## Configuration

- `docker_install_enabled`: install Docker (default `true`).
- `docker_update_packages`: deliberately upgrade packages on subsequent runs
  (default `false`; `present` installs missing packages without upgrading).
- `docker_packages`: override Docker package list only with tested versions.
- `docker_manage_group_membership`: allow management of Docker group
  (default `false`).
- `docker_group_users`: explicit list of trusted users, default empty.

Membership in the Docker group is effectively root-equivalent. By default no
account is added. Existing membership is not revoked. Privilege changes may
require a new login session.

## Playbook example

```yaml
- name: Provision Docker hosts
  hosts: docker_hosts
  become: true
  roles:
    - role: docker
      vars:
        docker_update_packages: false
        docker_manage_group_membership: false
```

Validate on a disposable VM with `ansible-playbook -i inventory playbook.yml
--check --diff` and an ordinary run. The second ordinary run should report
no changes. Check `docker version`, `docker compose version`,
`docker buildx version`, and `systemctl is-active docker`. Run an intentional
upgrade separately by setting `docker_update_packages: true`.

Changing apt/rpm repositories and upgrading Docker may restart services.
Schedule upgrades and rollback using a tested OS package snapshot/version
rollback, never by deleting /var/lib/docker. Check mode may not know remote
repository/key state on a fresh host, so it is not a replacement for a VM test.

The unused Nginx Compose sample under the Docker role, its empty handler and
directory placeholder, and its empty vars file were removed because Docker
provisioning does not own or deploy Nginx. If a downstream playbook imported
the old template directly, update it to the application-specific deployment
role before upgrading this role.
