#!/usr/bin/env ruby
# Reescreve todos os UUIDs dos templates para UUIDv4 validos (formato aceito pelo Zabbix:
# 32 hex sem hifens, com o 13o digito = "4" e o 17o em {8,9,a,b}).
# Deterministico: deriva do valor antigo + arquivo, para manter estabilidade e unicidade.
require 'digest'

DIR = File.dirname(File.expand_path(__FILE__))

# Converte uma seed em UUIDv4 hex (sem hifens), forcando os bits de versao/variante.
def v4_from(seed)
  h = Digest::SHA256.hexdigest(seed)[0, 32].chars
  h[12] = '4'                       # versao 4
  h[16] = %w[8 9 a b][h[16].to_i(16) % 4]  # variante
  h.join
end

Dir.glob(File.join(DIR, '*.yaml')).sort.each do |path|
  file = File.basename(path)
  text = File.read(path)
  seen = {}
  out = text.gsub(/uuid:\s*['"]?([0-9a-fA-F]{32})['"]?/) do
    old = $1
    nv = v4_from(old + '|' + file)
    # garante unicidade dentro do arquivo mesmo em caso improvavel de colisao
    nv = v4_from(nv + '|salt') while seen[nv]
    seen[nv] = true
    "uuid: #{nv}"
  end
  File.write(path, out)
  puts "#{file}: #{seen.size} uuid(s) reescritos"
end
