# Nextcloud Mail + Mailcow provisioning

Reutiliza APIs e serviços nativos do Nextcloud Mail e Mailcow para conectar contas elegíveis, preparar caixas novas e atribuir uma **assinatura padrão personalizada**, sem criar outro aplicativo Nextcloud. Esta role não interfere nas outras roles do repositório.

A instalação é opt-in: `mail_provision_enabled=false`, `mail_warmup_enabled=false` e `mail_provision_signature_enabled=false` por padrão. **Nunca executar sem valores por instância, autorização do host e uma versão fixada da role.**

A configuração da organização (domínio, grupo, nome, assinatura, função padrão, URLs/portas, contêiner, quota) pertence **exclusivamente ao inventário do consumidor**. Chaves Mailcow e baselines legadas ficam nos diretórios protegidos da instalação, não neste repositório.

## Comportamento

- Provisionamento conservador: não adota caixas antigas sem comprovação administrativa de titularidade; não altera contas manuais.
- Credencial IMAP/SMTP individual por usuário, sem expor chaves ou senhas em CLI.
- Aquecimento inicial em série usando `SyncJob` e pré-visualizações nativas do Mail; lock, estado persistente e retentativas.
- Assinatura inicial no Mail: campo `mail_signature_role` (Profile Fields, `users/private`) tem prioridade sobre `mail_provision_signature_default_role`; campo `mail_signature_state` (admin/hidden) protege personalizações posteriores. O texto usa `{name}`, `{role}`, `{organization}`, `{email}` e `{website}`. Assinaturas editadas manualmente deixam de ser gerenciadas.

## Instalação

1. Fixar o commit/tag revisado desta role no repositório consumidor. Verificar todos os consumidores antes de migrar de outra implementação: **roles de mesmo nome em projetos diferentes não são equivalentes automaticamente**.
2. Definir no inventário as variáveis `mail_provision_*` e habilitar primeiro apenas a instalação de arquivos, mantendo os crons desligados.
3. Executar o inicializador `files/nextcloud-mailcow-fields.php` na instância correta: primeiro leitura, depois escrita controlada. Não regenerar o baseline existente.
4. Homologar E2E com conta descartável (Mailcow + IMAP/SMTP + Mail + assinatura + privacidade), e só então habilitar os três recursos que forem necessários para a instância.
5. Usar `--limit <host>` e verificar mudança, saúde, consumidores e rollback em cada implantação.

## Testes

A partir da raiz desta role:

```bash
bash tests/check.sh
```

Os testes usam PHP 8.4 e 8.5 em contêineres **sem rede**, fixtures independentes de clientes, teste de renderização Docker e PHP nativo, verificação de limites de função e assinatura, idempotência, concorrência e retentativa do aquecimento. Se Ansible estiver disponível, validam também a sintaxe da role.

A extração para esta role pública **não autoriza trocar automaticamente o provisionador já implantado**. O repositório privado que consome a role deve preservar inventário, secrets, revisão de versão, testes proporcionais e rollback.
