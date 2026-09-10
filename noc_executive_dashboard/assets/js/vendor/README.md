# Bibliotecas de terceiros (vendor) — exportacao de PDF

O botao **Exportar PDF** do dashboard usa duas bibliotecas JavaScript:

- **html2canvas** — captura o dashboard (incluindo os graficos em `<canvas>`) como imagem.
- **jsPDF** — monta o arquivo PDF real e dispara o download.

## Modo de carregamento (hibrido)

O modulo tenta carregar estas libs nesta ordem:

1. **Localmente** (offline): a partir desta pasta `assets/js/vendor/`, se os arquivos existirem.
2. **Via CDN** (fallback): se os arquivos locais nao existirem, tenta baixar do jsDelivr.
   Isso requer que o **navegador** de quem abre o dashboard tenha acesso a internet
   (o servidor Zabbix NAO precisa de internet).

## Para funcionar 100% OFFLINE (recomendado)

Baixe os dois arquivos abaixo e salve nesta pasta com EXATAMENTE estes nomes:

| Arquivo esperado          | Baixe de |
|---------------------------|----------|
| `html2canvas.min.js`      | https://github.com/niklasvh/html2canvas/releases (asset `html2canvas.min.js`) — ou https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js |
| `jspdf.umd.min.js`        | https://github.com/parallax/jsPDF/releases — ou https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js |

Exemplo (rode em qualquer maquina com internet e depois copie para o servidor):

```bash
cd assets/js/vendor
curl -L -o html2canvas.min.js https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js
curl -L -o jspdf.umd.min.js  https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js
```

Depois de copiar os arquivos, o export funciona sem qualquer acesso a internet.

## Versoes testadas

- html2canvas 1.4.1
- jsPDF 2.5.2 (build UMD: expoe `window.jspdf.jsPDF`)
