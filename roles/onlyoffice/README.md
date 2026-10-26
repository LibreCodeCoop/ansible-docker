# OnlyOffice role

## Status and prerequisites

This role checks for Docker Compose v2 and Docker Engine access; it does not install Docker, deploy
OnlyOffice, restart containers or migrate any data. The earlier implementation
only installed Docker and notified a non-existent restart handler: it did not
deploy the provided Compose template. We deliberately do not silently begin
deploying an unvalidated third-party image or altering persistent volumes.

Provision Docker Engine explicitly first:

```yaml
- name: Prepare OnlyOffice hosts
  hosts: onlyoffice_hosts
  become: true
  roles:
    - docker
    - onlyoffice
```

The host must have Docker Engine, Compose v2, and Docker daemon permissions.
`onlyoffice_validate_prerequisites` is `true` by default.

## Existing deployment assets and migration

`files/compose.yml` and `templates/docker-compose.yml.j2` remain
unchanged for review and compatibility with manually operated deployments.
Their third-party, amd64-specific image, external networks, bind mounts,
secrets, health checks, and migration to an official supported OnlyOffice Docs
image require a separate tested deployment PR. Do not delete persistent data
or switch images without inspecting the existing environment.

Credentials and JWT secrets MUST be provisioned out of band; rotate credentials
that appeared in repository history. The checked-in `files/.env` needs a
separate security cleanup (including historical references). Do not deploy
from that file. A removed or deactivated application deployment is NOT implied
by the validation-only role.
