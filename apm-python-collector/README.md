# APM Collector para Zabbix (Python + zabbix_sender)

Coletor de **APM** em Python que roda num servidor Linux (ex.: o **Zabbix proxy**),
raspa um endpoint no formato **Prometheus**, calcula os *golden signals* por rota e
**empurra** os valores ao Zabbix via `zabbix_sender` (itens do tipo **Zabbix trapper**).

Foi criado como alternativa aos templates LLD via HTTP agent: aqui toda a lógica fica
num script transparente e depurável, sem depender de importação de XML/YAML.

## Por que esta abordagem

- **Sem dependências:** usa apenas a biblioteca padrão do Python 3 (PyYAML é opcional).
- **Push model:** ideal para rodar no proxy por `systemd timer`/cron, sem expor o endpoint ao server.
- **Descoberta dinâmica:** o script publica um item de **LLD** (`apm.discovery`) e o Zabbix
  cria os itens por rota automaticamente — o mesmo script serve para qualquer aplicação.
- **Fácil de auditar:** `--dry-run` mostra exatamente o que seria enviado.

## Golden signals coletados

| Sinal | Item enviado | Como é calculado |
|-------|--------------|------------------|
| Throughput | `apm.requests.rate[uri,method,status]` | `Δcount / Δt` (req/s) entre execuções |
| Errors | `apm.errors.rate[uri,method]` | taxa (req/s) das séries com `status` 5xx |
| Latency | `apm.latency.avg[uri,method]` | `Δsum / Δcount` (latência média na janela) |
| Saturation | chaves configuráveis | valor direto de métricas escolhidas |
| Health | `apm.up`, `apm.scrape.ms` | 1/0 se raspou, e tempo de scrape (ms) |

> As taxas por segundo são calculadas **entre execuções**, comparando com o estado
> anterior salvo em `state_file`. Mantenha o intervalo do timer estável (padrão: 30s).

## Requisitos

- Python 3.6+
- `zabbix_sender` instalado no host (pacote `zabbix-sender`).
- Conectividade do host até o server/proxy Zabbix na porta 10051.
- O host correspondente **deve existir no Zabbix** com os itens abaixo.

## Instalação

```sh
sudo mkdir -p /opt/apm-collector
sudo cp apm_collector.py /opt/apm-collector/
sudo cp apm_collector.yaml /etc/zabbix/apm_collector.yaml
sudo chmod +x /opt/apm-collector/apm_collector.py

# (opcional) YAML na config; sem PyYAML, escreva a config em JSON.
pip3 install -r requirements.txt || true

# systemd
sudo cp systemd/apm-collector.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now apm-collector.timer
```

Alternativa por **cron** (a cada 30s = duas linhas):

```cron
* * * * * zabbix /usr/bin/python3 /opt/apm-collector/apm_collector.py --config /etc/zabbix/apm_collector.yaml
* * * * * zabbix sleep 30; /usr/bin/python3 /opt/apm-collector/apm_collector.py --config /etc/zabbix/apm_collector.yaml
```

## Configuração

Edite `/etc/zabbix/apm_collector.yaml`. Campos principais:

| Campo | Descrição |
|-------|-----------|
| `metrics_url` | Endpoint Prometheus da aplicação |
| `zabbix_server` / `zabbix_port` | Endereço do **server ou proxy** e porta (10051) |
| `zabbix_host` | Nome do host no Zabbix ao qual os itens pertencem |
| `metric_requests` | Métrica contadora de requests (ex.: `http_server_requests_seconds_count`) |
| `metric_latency_sum` / `_count` | Métricas para latência média |
| `label_uri` / `label_method` / `label_status` | Nomes dos labels de rota/método/status |
| `uri_include` / `uri_exclude` | Regex para filtrar rotas |
| `saturation_metrics` | Mapa `metrica_prometheus: chave_zabbix` |
| `state_file` | Arquivo de estado para cálculo de rate |

Teste sem enviar:

```sh
python3 /opt/apm-collector/apm_collector.py --config /etc/zabbix/apm_collector.yaml --dry-run
```

## Configuração no Zabbix

Crie um **host** (ou template vinculado) com o nome de `zabbix_host` e os itens:

### 1. Itens trapper fixos

| Nome | Tipo | Chave | Tipo de informação |
|------|------|-------|--------------------|
| APM up | Zabbix trapper | `apm.up` | Numérico (unsigned) |
| APM scrape time | Zabbix trapper | `apm.scrape.ms` | Numérico (float), unidade `ms` |

### 2. Regra de descoberta (LLD)

- **Tipo:** Zabbix trapper
- **Chave:** `apm.discovery`
- O script envia um JSON com macros `{#URI}`, `{#METHOD}`, `{#STATUS}`.

### 3. Item prototypes (dentro da regra LLD)

| Nome | Tipo | Chave | Info |
|------|------|-------|------|
| Requests rate [{#METHOD} {#URI}] {#STATUS} | Zabbix trapper | `apm.requests.rate[{#URI},{#METHOD},{#STATUS}]` | float, `!rps` |
| Errors rate [{#METHOD} {#URI}] | Zabbix trapper | `apm.errors.rate[{#URI},{#METHOD}]` | float, `!rps` |
| Latency avg [{#METHOD} {#URI}] | Zabbix trapper | `apm.latency.avg[{#URI},{#METHOD}]` | float, `s` |

> **Importante:** a ordem dos parâmetros na chave (`[{#URI},{#METHOD},{#STATUS}]`)
> tem que ser **idêntica** à que o script envia. O script usa `uri,method,status`.

### 4. Trigger prototypes (sugestões)

- Latência alta: `min(/HOST/apm.latency.avg[{#URI},{#METHOD}],5m)>1`
- Erros sustentados: `min(/HOST/apm.errors.rate[{#URI},{#METHOD}],5m)>0`
- App fora do ar: `max(/HOST/apm.up,#3)=0`

## Defaults por stack

Ajuste `metric_*` e `label_*` conforme a instrumentação:

| Stack | métrica base | uri | method | status |
|-------|--------------|-----|--------|--------|
| Java/Spring (Micrometer) | `http_server_requests_seconds` | `uri` | `method` | `status` |
| Node prom-client | `http_request_duration_seconds` | `route` | `method` | `code` |
| Python prometheus_client | `http_request_duration_seconds` | `handler` | `method` | `status` |
| Go client_golang | `http_request_duration_seconds` | `path` | `method` | `code` |
| .NET prometheus-net | `http_request_duration_seconds` | `controller` | `method` | `code` |

Para essas stacks use `metric_requests: <base>_count`,
`metric_latency_sum: <base>_sum`, `metric_latency_count: <base>_count`.

## Solução de problemas

- **`command not found: zabbix_sender`** → instale o pacote `zabbix-sender` e ajuste `zabbix_sender` na config.
- **Valores não aparecem no Zabbix** → confira: (1) host existe e os itens/chaves batem exatamente;
  (2) o host aceita trapper vindo do IP do coletor; (3) rode com `--dry-run` e confira as chaves.
- **Rates sempre 0** → contadores estáticos (sem tráfego) ou primeira execução (sem baseline). É esperado.
- **`not permitted to write state_file`** → ajuste `state_file` para um caminho gravável (padrão `/var/tmp`).

## Segurança

- Se o endpoint exigir auth, use `http_headers` (ex.: `Authorization: Bearer ...`).
- O `systemd` service já inclui *hardening* básico (`ProtectSystem`, `NoNewPrivileges` etc.).

## Licença

MIT.
