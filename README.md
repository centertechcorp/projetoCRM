# Projeto CRM

Base Laravel + PostgreSQL para sincronizar dados do GestãoClick das lojas CENTER,
GENIUS e MIXCELL. O fuso da aplicação é `America/Sao_Paulo`.

## Rodar localmente com Docker Desktop

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
```

A aplicação fica em `http://localhost:8000` e o PostgreSQL em `localhost:5432`
(`crm` / `postgres` / `password`). Para acompanhar o servidor: `./vendor/bin/sail logs -f`.

## Tabelas

- `stores`: as três contas sincronizadas.
- `sync_runs`: execução, contadores e erro de cada sincronização.
- `gc_raw_records`: resposta crua e imutável da API; `payload_hash` é SHA-256 do payload.
- `gc_sale_statuses`: status recebido do GestãoClick e seu mapeamento interno.
- `sales`: venda tratada, recalculável a partir da camada crua. `gc_public_hash` guarda o link público do GestãoClick.
- `sale_items`: produtos de uma venda; IDs de produto e variação continuam como strings do GestãoClick.
- `sale_payments`: parcelas e formas de pagamento da venda.
- `events`: eventos internos para processamento assíncrono.
- `users`: contas locais e seu papel de acesso. Pode haver até dois owners, ocupando os slots 1 e 2.

Os valores financeiros usam `numeric(12,2)` e quantidades usam `numeric(12,3)`.
As tabelas tratadas têm chaves únicas para upsert idempotente por loja e ID GestãoClick.

### Papéis iniciais

| Papel | Usuários | Acesso |
| --- | --- | --- |
| `owner` | Caio | Tudo, incluindo criar e remover admins, ver tokens e apagar dados. |
| `admin` | Matheus, Rodrigo | Sincronização, mapeamento de situações, usuários abaixo de admin e todas as lojas. |
| `manager` | João Pedro | CRM, relatórios e lojas; pode reatribuir leads. |
| `seller` | Vendedoras | Somente leads próprios e sua loja. |
