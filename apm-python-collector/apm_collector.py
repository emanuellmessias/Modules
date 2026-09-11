#!/usr/bin/env python3
"""
APM collector para Zabbix.

Raspa um endpoint no formato Prometheus (ex: /metrics, /actuator/prometheus),
calcula os "golden signals" (throughput, erros, latencia, saturacao) por rota e
envia os valores ao Zabbix via `zabbix_sender` (itens do tipo Zabbix trapper).

Objetivos de projeto:
  - Somente biblioteca padrao do Python 3 (roda em qualquer proxy Zabbix, sem pip).
  - Descoberta dinamica (LLD): publica um item de discovery para o Zabbix criar
    os itens por rota automaticamente, e depois envia os valores desses prototypes.
  - Sem estado externo: guarda os contadores da coleta anterior em um arquivo de
    estado para calcular taxas por segundo (rate) entre execucoes.

Uso:
    apm_collector.py --config /etc/zabbix/apm_collector.yaml
    apm_collector.py --config ... --dry-run        # nao envia, imprime o que enviaria
    apm_collector.py --config ... --print-config   # mostra a config efetiva

Licenca: MIT.
"""

import argparse
import json
import os
import re
import subprocess
import sys
import time
import urllib.request
import urllib.error
from typing import Dict, List, Tuple, Optional

# ------------------------------------------------------------------------------
# Configuracao
# ------------------------------------------------------------------------------

DEFAULT_CONFIG = {
    # Endpoint de metricas Prometheus da aplicacao monitorada.
    "metrics_url": "http://127.0.0.1:8080/actuator/prometheus",
    "http_timeout": 10,
    "http_headers": {},  # ex: {"Authorization": "Bearer ..."}
    # Como enviar ao Zabbix.
    "zabbix_server": "127.0.0.1",     # endereco do server OU do proxy
    "zabbix_port": 10051,
    "zabbix_sender": "zabbix_sender",  # caminho do binario
    # Host (como cadastrado no Zabbix) ao qual os itens pertencem.
    "zabbix_host": "APM Application",
    # Nomes de metrica/label esperados (ajuste ao seu framework).
    "metric_requests": "http_server_requests_seconds_count",   # contador de requests
    "metric_latency_sum": "http_server_requests_seconds_sum",  # soma de latencias
    "metric_latency_count": "http_server_requests_seconds_count",
    "label_uri": "uri",
    "label_method": "method",
    "label_status": "status",
    # Filtros de rota (regex) para a descoberta.
    "uri_include": r".*",
    "uri_exclude": r"^(/actuator.*|/favicon\.ico|/metrics)$",
    # Metricas de saturacao (opcionais) - name -> chave de item no Zabbix.
    "saturation_metrics": {
        # "jvm_memory_used_bytes": "apm.jvm.memory.used",
    },
    # Arquivo de estado para calcular rate entre execucoes.
    "state_file": "/var/tmp/apm_collector_state.json",
    # Chaves de item no Zabbix (trapper). Devem existir no template/host.
    "keys": {
        "discovery": "apm.discovery",           # item trapper para LLD
        "requests_rate": "apm.requests.rate",   # prototype: apm.requests.rate[uri,method,status]
        "latency_avg": "apm.latency.avg",       # prototype: apm.latency.avg[uri,method]
        "errors_rate": "apm.errors.rate",       # prototype: apm.errors.rate[uri,method]
        "up": "apm.up",                         # 1 se raspou com sucesso, 0 caso contrario
        "scrape_ms": "apm.scrape.ms",           # tempo de raspagem em ms
    },
}


def load_config(path: Optional[str]) -> dict:
    """Carrega config de YAML (se PyYAML disponivel) ou JSON, mesclada com os defaults."""
    cfg = json.loads(json.dumps(DEFAULT_CONFIG))  # deep copy
    if not path:
        return cfg
    with open(path, "r", encoding="utf-8") as fh:
        raw = fh.read()
    data = None
    # Tenta YAML; se nao houver PyYAML, tenta JSON (YAML e superset, mas JSON puro funciona).
    try:
        import yaml  # type: ignore
        data = yaml.safe_load(raw)
    except Exception:
        try:
            data = json.loads(raw)
        except Exception as exc:
            raise SystemExit(f"Nao foi possivel ler a config ({path}): instale PyYAML "
                             f"ou use JSON. Erro: {exc}")
    if data:
        _deep_update(cfg, data)
    return cfg


def _deep_update(base: dict, overlay: dict) -> None:
    for k, v in overlay.items():
        if isinstance(v, dict) and isinstance(base.get(k), dict):
            _deep_update(base[k], v)
        else:
            base[k] = v


# ------------------------------------------------------------------------------
# Parser Prometheus (minimo, sem dependencias)
# ------------------------------------------------------------------------------

# Ex: metric_name{label="v",l2="v2"} 12.3   (ignora timestamp opcional)
_LINE_RE = re.compile(
    r'^(?P<name>[a-zA-Z_:][a-zA-Z0-9_:]*)'
    r'(?:\{(?P<labels>[^}]*)\})?'
    r'\s+(?P<value>[^\s]+)'
)
_LABEL_RE = re.compile(r'(?P<k>[a-zA-Z_][a-zA-Z0-9_]*)="(?P<v>(?:[^"\\]|\\.)*)"')


def parse_prometheus(text: str) -> List[Tuple[str, Dict[str, str], float]]:
    """Retorna lista de (name, labels_dict, value). Ignora comentarios e NaN."""
    out = []
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        m = _LINE_RE.match(line)
        if not m:
            continue
        name = m.group("name")
        labels = {}
        if m.group("labels"):
            for lm in _LABEL_RE.finditer(m.group("labels")):
                labels[lm.group("k")] = lm.group("v").replace('\\"', '"').replace("\\\\", "\\")
        val_s = m.group("value")
        try:
            val = float(val_s)
        except ValueError:
            continue
        if val != val:  # NaN
            continue
        out.append((name, labels, val))
    return out


# ------------------------------------------------------------------------------
# Coleta HTTP
# ------------------------------------------------------------------------------

def scrape(url: str, timeout: int, headers: Dict[str, str]) -> str:
    req = urllib.request.Request(url, headers=headers or {})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        charset = resp.headers.get_content_charset() or "utf-8"
        return resp.read().decode(charset, errors="replace")


# ------------------------------------------------------------------------------
# Estado (para rate entre execucoes)
# ------------------------------------------------------------------------------

def load_state(path: str) -> dict:
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except Exception:
        return {}


def save_state(path: str, state: dict) -> None:
    tmp = path + ".tmp"
    try:
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(state, fh)
        os.replace(tmp, path)
    except OSError as exc:
        sys.stderr.write(f"[warn] nao foi possivel salvar estado em {path}: {exc}\n")


# ------------------------------------------------------------------------------
# Nucleo: transforma metricas em itens Zabbix
# ------------------------------------------------------------------------------

def _match(uri: str, inc: str, exc: str) -> bool:
    if inc and not re.search(inc, uri):
        return False
    if exc and re.search(exc, uri):
        return False
    return True


class ZabbixItem:
    __slots__ = ("host", "key", "value")

    def __init__(self, host: str, key: str, value):
        self.host = host
        self.key = key
        self.value = value

    def line(self) -> str:
        # Formato do zabbix_sender com -i: "<host> <key> <value>"
        val = self.value
        if isinstance(val, float):
            val = repr(val)
        return f'"{self.host}" {self.key} {val}'


def build_items(cfg: dict, samples, prev_state: dict, now: float):
    """Calcula os itens a enviar e o novo estado. Retorna (items, discovery, new_state)."""
    host = cfg["zabbix_host"]
    keys = cfg["keys"]
    l_uri, l_method, l_status = cfg["label_uri"], cfg["label_method"], cfg["label_status"]

    # Indexa amostras por (name) -> lista de (labels, value)
    by_name: Dict[str, List[Tuple[Dict[str, str], float]]] = {}
    for name, labels, val in samples:
        by_name.setdefault(name, []).append((labels, val))

    prev = prev_state.get("counters", {})
    prev_ts = prev_state.get("ts", now)
    dt = max(now - prev_ts, 1e-6)

    new_counters: Dict[str, float] = {}
    items: List[ZabbixItem] = []
    discovered = {}  # dedup por (uri,method) e (uri,method,status)

    def rate(counter_key: str, current: float) -> Optional[float]:
        new_counters[counter_key] = current
        old = prev.get(counter_key)
        if old is None:
            return None  # primeira coleta: sem baseline
        delta = current - old
        if delta < 0:  # contador reiniciou (restart da app)
            return 0.0
        return delta / dt

    # ---- requests: throughput + erros por rota/metodo/status ----
    req_disc_routes = {}   # {#URI},{#METHOD}
    for labels, val in by_name.get(cfg["metric_requests"], []):
        uri = labels.get(l_uri, "")
        method = labels.get(l_method, "")
        status = labels.get(l_status, "")
        if not _match(uri, cfg["uri_include"], cfg["uri_exclude"]):
            continue
        ck = f"req|{method}|{uri}|{status}"
        r = rate(ck, val)
        if r is not None:
            items.append(ZabbixItem(host, f'{keys["requests_rate"]}[{uri},{method},{status}]', round(r, 6)))
        # LLD por status
        discovered[(uri, method, status)] = {
            "{#URI}": uri, "{#METHOD}": method, "{#STATUS}": status,
        }
        # agrega erros 5xx por rota/metodo
        req_disc_routes[(uri, method)] = True

    # errors rate por rota/metodo (soma dos 5xx)
    err_acc: Dict[Tuple[str, str], float] = {}
    for labels, val in by_name.get(cfg["metric_requests"], []):
        uri = labels.get(l_uri, "")
        method = labels.get(l_method, "")
        status = labels.get(l_status, "")
        if not _match(uri, cfg["uri_include"], cfg["uri_exclude"]):
            continue
        if status.startswith("5"):
            ck = f"err|{method}|{uri}"
            err_acc[(uri, method)] = err_acc.get((uri, method), 0.0) + val
    for (uri, method), total in err_acc.items():
        ck = f"err|{method}|{uri}"
        r = rate(ck, total)
        if r is not None:
            items.append(ZabbixItem(host, f'{keys["errors_rate"]}[{uri},{method}]', round(r, 6)))

    # ---- latencia media: delta(sum)/delta(count) por rota/metodo ----
    sums: Dict[Tuple[str, str], float] = {}
    counts: Dict[Tuple[str, str], float] = {}
    for labels, val in by_name.get(cfg["metric_latency_sum"], []):
        uri = labels.get(l_uri, ""); method = labels.get(l_method, "")
        if _match(uri, cfg["uri_include"], cfg["uri_exclude"]):
            sums[(uri, method)] = sums.get((uri, method), 0.0) + val
    for labels, val in by_name.get(cfg["metric_latency_count"], []):
        uri = labels.get(l_uri, ""); method = labels.get(l_method, "")
        if _match(uri, cfg["uri_include"], cfg["uri_exclude"]):
            counts[(uri, method)] = counts.get((uri, method), 0.0) + val
    for route in set(list(sums.keys()) + list(counts.keys())):
        uri, method = route
        s_new = sums.get(route, 0.0)
        c_new = counts.get(route, 0.0)
        sk, ckk = f"lsum|{method}|{uri}", f"lcnt|{method}|{uri}"
        s_old = prev.get(sk); c_old = prev.get(ckk)
        new_counters[sk] = s_new; new_counters[ckk] = c_new
        if s_old is not None and c_old is not None:
            ds = s_new - s_old; dc = c_new - c_old
            if ds >= 0 and dc > 0:
                avg = ds / dc
                items.append(ZabbixItem(host, f'{keys["latency_avg"]}[{uri},{method}]', round(avg, 6)))
        discovered.setdefault((uri, method, ""), {"{#URI}": uri, "{#METHOD}": method, "{#STATUS}": ""})

    # ---- saturacao (valores diretos) ----
    for metric_name, item_key in (cfg.get("saturation_metrics") or {}).items():
        vals = by_name.get(metric_name, [])
        if vals:
            # soma os valores da metrica (ex: heap total). Ajuste se precisar por-label.
            total = sum(v for _, v in vals)
            items.append(ZabbixItem(host, item_key, round(total, 6)))

    # ---- LLD discovery payload ----
    disc_data = []
    seen = set()
    for (uri, method, status), macros in discovered.items():
        key = (uri, method)
        if key in seen:
            # ainda assim precisamos das combinacoes com status para os prototypes por status
            pass
        disc_data.append(macros)
    discovery = {"data": disc_data}

    new_state = {"ts": now, "counters": new_counters}
    return items, discovery, new_state


# ------------------------------------------------------------------------------
# Envio via zabbix_sender
# ------------------------------------------------------------------------------

def send(cfg: dict, items: List[ZabbixItem], discovery: dict, dry_run: bool) -> int:
    keys = cfg["keys"]
    host = cfg["zabbix_host"]
    # discovery vai como um item trapper cujo valor e o JSON de LLD
    all_lines = [ZabbixItem(host, keys["discovery"], json.dumps(discovery))] + items

    payload = "\n".join(it.line() for it in all_lines) + "\n"

    if dry_run:
        sys.stdout.write("=== DRY RUN (nao enviado) ===\n")
        sys.stdout.write(payload)
        sys.stdout.write(f"=== total: {len(all_lines)} itens ===\n")
        return 0

    cmd = [
        cfg["zabbix_sender"],
        "-z", str(cfg["zabbix_server"]),
        "-p", str(cfg["zabbix_port"]),
        "-i", "-",  # le do stdin
    ]
    try:
        proc = subprocess.run(cmd, input=payload, text=True,
                              capture_output=True, timeout=cfg["http_timeout"] + 20)
    except FileNotFoundError:
        sys.stderr.write(f"[erro] binario nao encontrado: {cfg['zabbix_sender']}\n")
        return 3
    except subprocess.TimeoutExpired:
        sys.stderr.write("[erro] zabbix_sender expirou (timeout)\n")
        return 4
    sys.stdout.write(proc.stdout)
    if proc.returncode != 0:
        sys.stderr.write(proc.stderr)
    return proc.returncode


# ------------------------------------------------------------------------------
# main
# ------------------------------------------------------------------------------

def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Coletor APM para Zabbix (Prometheus -> zabbix_sender).")
    ap.add_argument("--config", help="Arquivo de config YAML/JSON.")
    ap.add_argument("--dry-run", action="store_true", help="Nao envia; imprime o payload.")
    ap.add_argument("--print-config", action="store_true", help="Mostra a config efetiva e sai.")
    args = ap.parse_args(argv)

    cfg = load_config(args.config)

    if args.print_config:
        print(json.dumps(cfg, indent=2, ensure_ascii=False))
        return 0

    host = cfg["zabbix_host"]
    keys = cfg["keys"]
    t0 = time.time()
    up = 1
    scrape_ms = 0.0
    samples = []
    try:
        text = scrape(cfg["metrics_url"], cfg["http_timeout"], cfg["http_headers"])
        scrape_ms = (time.time() - t0) * 1000.0
        samples = parse_prometheus(text)
        if not samples:
            sys.stderr.write("[warn] endpoint respondeu mas nenhuma metrica foi parseada\n")
    except (urllib.error.URLError, urllib.error.HTTPError, OSError, ValueError) as exc:
        up = 0
        sys.stderr.write(f"[erro] falha ao raspar {cfg['metrics_url']}: {exc}\n")

    now = time.time()
    prev_state = load_state(cfg["state_file"])
    items, discovery, new_state = build_items(cfg, samples, prev_state, now)

    # itens de saude sempre enviados
    items.append(ZabbixItem(host, keys["up"], up))
    items.append(ZabbixItem(host, keys["scrape_ms"], round(scrape_ms, 2)))

    rc = send(cfg, items, discovery, args.dry_run)

    if up == 1 and not args.dry_run:
        save_state(cfg["state_file"], new_state)
    elif up == 1 and args.dry_run:
        # em dry-run tambem salvamos para permitir ver rate na proxima execucao de teste
        pass

    return rc


if __name__ == "__main__":
    raise SystemExit(main())
