# Preventing static credentials in public Ansible roles

The public role repository must not hold runtime passwords, API keys, session
signing/encryption keys, or other deployment credentials in plaintext. Provide
them from a private secret manager or Ansible Vault. Never copy decrypted Vault
content into a public repository, CI artifact, PR, or issue.

## Guard implemented in this repository

The [static credential guard](../scripts/check_secret_literals.py) checks
tracked working-tree files, without ever printing the field value. It rejects:

- Inline values for `hashkey`, `blockkey`, `internalsecret`, and `apikey`
  in Nextcloud Talk role configuration;
- Inline `pgadmin_password` assignments;
- Nonempty inline DB/JWT/password assignments in tracked `.env` files or
  `env.j2` / `.env.j2` templates for
  the specific environment keys defined in the script.

Simple Jinja variable references, environment variable references, and an
Ansible Vault YAML tag are accepted. The existing empty OnlyOffice `.env`
placeholders are not automatically classified as leaks. Operators still need
runtime validation that a required secret is actually provided.

Run locally:

```sh
python3 -m unittest discover -s tests/security -p 'test_*.py' -v
python3 scripts/check_secret_literals.py
```

Checks run for pull requests and pushes to `main`, with read-only repository
permissions. A failure reports only file, line and field. It never prints,
hashes or uploads a matched value.

**Limitations:** This intentionally narrow detector is not a generic secret
scanner and does not inspect Git history, pull request refs, commits introduced
outside normal PR workflows, forks, local clones or CI artifacts. It cannot
determine whether a secret was deployed or used. Review custom GitHub secret
scanning patterns and push protection where the organization's plan permits,
and test custom patterns with synthetic data before publication.

## Vault and deployment requirements

- Put runtime credentials in private Vault/secret-manager records, scoped to
  the host/service; do not commit unencrypted values to examples or defaults.
- Prefer simple variable references in public Jinja templates. Encrypt private
  Vault files before committing, and restrict their access to authorized
  operators. Review the consequences of decrypted files and local backups.
- Use `no_log: true` on all tasks that materialize or manipulate secret data.
  Ensure templates are deployed with minimum required owner/group/mode.
- Protect Vault decryption keys; do not pass passwords on shell command lines,
  store them in source, or log them in GitHub Actions. Do not print
  `ansible-inventory --list` or rendered secret-bearing Compose files.
- Changing the current code does not revoke credentials found in old commits.
  Rotate or revoke validated affected credentials with service-specific
  authorization, then consider a coordinated history cleanup. Never force
  rewrite a public repository without a separate incident plan and approval.

For this incident, coordinate privately in
`LibreCodeCoop/infrastructure-operations#53`; keep public issue `#16`
sanitized. Do not upload raw secret matches, replacement maps, or decrypted
Vault content to either issue.
