# Zabbix APM Templates (Prometheus + LLD)

Templates de **APM (Application Performance Monitoring)** para **Zabbix 7.4**, dinâmicos e genéricos.
Nenhum item é fixo: as métricas são **descobertas automaticamente** via **Low-Level Discovery (LLD)**
a partir de um endpoint no formato **Prometheus**. O mesmo template serve para qualquer aplicação
que exponha `/metrics` (ou equivalente).

## Como funciona

```
[HTTP agent master item]  apm.get_metrics   -> baixa a página /metrics inteira (1 request, bulk)
        │
        ├── LLD rule (PROMETHEUS_TO_JSON)  -> descobre as séries existentes
        │       filtra rotas por regex (include/exclude)
        │       └── item prototypes  -> throughput, erros, latência por rota (PROMETHEUS_PATTERN)
        │           trigger prototypes -> alerta por rota
        └── ...
```

- **Bulk collection:** um único item HTTP baixa toda a página; os demais são *dependent items*
  que extraem cada métrica com preprocessing — evita dezenas de requisições HTTP.
- **Macros LLD automáticas** geradas pelo `PROMETHEUS_TO_JSON`:
  - `{#__NAME__}` — nome da métrica
  - `{#LABELS.<LABEL>}` — valor de cada label (ex.: `{#LABELS.URI}`, `{#LABELS.STATUS}`)
  - `{#HELP}`, `{#TYPE}` — metadados

## Golden signals cobertos

| Sinal | Como é medido |
|-------|----------------|
| **Throughput** | `rate` do contador de requests por rota |
| **Errors** | requests com `status`/`code` 5xx por rota |
| **Latency** | p95 (summary) **ou** latência média + conformidade de SLO (histogram) |
| **Saturation** | via labels de recurso descobertos (estender conforme a app) |

## Arquivos

| Arquivo | Base | Para que serve |
|---------|------|----------------|
| `apm_generic_summary_by_http.yaml`   | summary   | Genérico, ajustável por macros. Use quando a latência é **summary** (label `quantile`). |
| `apm_generic_histogram_by_http.yaml` | histogram | Genérico. Use quando a latência é **histogram** (`_bucket`/`_sum`/`_count`). |
| `apm_java_spring_by_http.yaml`       | summary   | Spring Boot / Micrometer (`/actuator/prometheus`). |
| `apm_nodejs_promclient_by_http.yaml` | histogram | Node.js com `prom-client`. |
| `apm_python_prometheus_by_http.yaml` | histogram | Python com `prometheus_client`. |
| `apm_go_client_by_http.yaml`         | histogram | Go com `client_golang`. |
| `apm_dotnet_prometheus_by_http.yaml` | histogram | .NET com `prometheus-net`. |

> **Summary vs. histogram:** o Zabbix **não** calcula `histogram_quantile` como o PromQL.
> Por isso, para histogramas os templates monitoram **latência média** (`_sum`/`_count`) e
> **conformidade de SLO** (fração de requests dentro de `{$APM.SLO.LE}` segundos, via bucket `le`),
> em vez de tentar reconstruir o p95. Se sua app expõe *summary* com `quantile`, prefira a variante summary.

## Instalação

1. No Zabbix: **Data collection → Templates → Import**.
2. Selecione o `.yaml` da sua stack (ou um dos genéricos).
3. Vincule o template a um host e ajuste as macros (abaixo).

## Macros principais

| Macro | Descrição | Default (genérico) |
|-------|-----------|--------------------|
| `{$APM.URL}` | Endpoint de métricas Prometheus | `http://localhost:8080/actuator/prometheus` |
| `{$APM.HTTP.PROXY}` | Proxy HTTP (opcional) | *(vazio)* |
| `{$APM.METRIC.REQUESTS}` | Nome da métrica de contagem de requests (summary) | `http_server_requests_seconds_count` |
| `{$APM.METRIC.LATENCY}` | Nome (base) da métrica de latência | `http_server_requests_seconds` |
| `{$APM.LABEL.URI}` / `.METHOD` / `.STATUS` | Nomes dos labels de rota/método/status | `uri` / `method` / `status` |
| `{$APM.LLD.URI.MATCHES}` | Regex de rotas a **incluir** | `.*` |
| `{$APM.LLD.URI.NOT_MATCHES}` | Regex de rotas a **excluir** | `^(/actuator.*|/favicon.ico|/metrics)$` |
| `{$APM.ERROR.RATE.MAX}` | Limiar (%) de erro por rota | `5` |
| `{$APM.LATENCY.MAX}` | Limiar de latência p95 (s) — summary | `1` |
| `{$APM.SLO.LE}` | Limite (s) do bucket de SLO — histogram | `0.5` |
| `{$APM.SLO.MIN}` | % mínimo dentro do SLO — histogram | `99` |
| `{$APM.LATENCY.AVG.MAX}` | Limiar de latência média (s) — histogram | `1` |

## Defaults por stack

Cada template de stack já vem com os nomes de métrica/label típicos daquele ecossistema.
**Confirme os nomes reais** na sua aplicação (a tela **Test** da regra LLD mostra os labels descobertos):

| Stack | `METRIC.LATENCY` | label rota | label método | label status |
|-------|------------------|------------|--------------|--------------|
| Java/Spring | `http_server_requests_seconds` | `uri` | `method` | `status` |
| Node prom-client | `http_request_duration_seconds` | `route` | `method` | `code` |
| Python prometheus_client | `http_request_duration_seconds` | `handler` | `method` | `status` |
| Go client_golang | `http_request_duration_seconds` | `path` | `method` | `code` |
| .NET prometheus-net | `http_request_duration_seconds` | `controller` | `method` | `code` |

## Ajustar / regenerar

Os templates de stack são gerados a partir dos genéricos por `.generate.rb`
(substitui nome, defaults de macro e UUIDs). Para alterar todos de uma vez, edite o(s)
genérico(s) e rode:

```sh
ruby .generate.rb
```

## Limitações e observações

- **Histogram ≠ percentil exato.** A latência média e o SLO por bucket são aproximações
  operacionais; não reproduzem `histogram_quantile`.
- **Labels em maiúsculas.** O `PROMETHEUS_TO_JSON` expõe labels como `{#LABELS.URI}` (maiúsculas),
  independentemente do case original do label na métrica.
- **Cardinalidade.** Rotas com IDs na URL (ex.: `/users/123`) geram muitas séries. Use
  `{$APM.LLD.URI.NOT_MATCHES}` ou normalização de rota na aplicação para evitar explosão de itens.
- **UUIDs.** O Zabbix exige `UUID` no formato **v4** (32 hex, 13º dígito `4`, 17º em `8/9/a/b`).
  Todos os templates já cumprem isso; o `.generate.rb` e o `.fix_uuids.rb` mantêm/reparam esse formato.
- Validado estruturalmente (YAML + UUIDv4 válido + consistência de nomes). **Teste a importação num
  Zabbix 7.4 real** antes de usar em produção, pois os nomes de métrica/label variam conforme a instrumentação.
