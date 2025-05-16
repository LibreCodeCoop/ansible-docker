Nextcloud-talk
=========

Este ansible-role faz a instalação dos containers necessário para execução do High Performance Backend (spreedbackend).

Requirements
------------

Pré-requisito:
- Ser capaz de requisitar certificados válidos
- Crie as seguintes entradas no seu provedor de registro (substitua seudominio.tld pelo seu domínio):
      - talk-turn.seudominio.tld
      - talksignaling.seudominio.tld
      - janus.seudominio.tld

- Ter as seguintes portas liberadas:
      - "3478:3478/tcp/udp"
      - "3479:3479/tcp/udp"
      - "5349:5349/tcp/udp"
      - "5350:5350/tcp/udp"


Variáveis 
--------------

Defina as seguintes variáveis:
- 
-


Dependências
------------

É desejável mas não necessário ter o Nextcloud instalado na instância.

Examplo de Playbook
----------------

Exemplo de uso


License
-------

BSD


