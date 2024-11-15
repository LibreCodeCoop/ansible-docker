# Configurações

Ambiente genérico com `docker compose.yml` para deploy de serviços.

O primeiro passo é criar o arquivo `inventory.ini` e preencher com dados do host de destino.

Exemplo:
```
[webserver]
exemplo.com.br ansible_host=ip ansible_user=root
192.168.0.100

[databases]
200.10.2.5
```

Onde `ansible_host` e `ansible_user` são variáveis que serão utilizadas apenas no host `exemplo.com.br`.


### Caso o deploy possuir secrets, usar a etapa abaixo:

Criar arquivo `.vault.pass.txt` com uma senha para proteger alguns dados sensíveis
Criar o arquivo `.secrets.yml` com o conteúdo abaixo

```
variavel: valor
```

Executar o ansible-vault para encriptar esses dados
```
ansible-vault encrypt roles/docker/vars/.secrets.yml
```

Caso queira ver o conteúdo do arquivo, basta alterar encrypt para view e, caso quiser editar, alterar de encrypt para edit.

OBS: Há um controle de quais tasks serão executadas. Isso pode ser controlado no arquivo `docker/defaults/main.yml`

# Execução
Executar a playbook
```
ansible-playbook playbook.yml -v --inventory-file invetory.ini
```

Caso tenham secrets:
```
ansible-playbook playbook.yml -v \
          --inventory-file hosts \
          --extra-vars "@roles/docker/vars/.secrets.yml"
```


# Parâmetros
- Utilizando outra chave para se conectar aos hosts, defina `--key-file chave-ssh`:

    - `ansible-playbook --inventory hosts.yml --key-file chave-ssh requirements-playbook.yml`