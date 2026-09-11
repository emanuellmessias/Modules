#!/usr/bin/env ruby
# Renomeia templates para nomes sem caracteres que quebram o parser de expressao
# do Zabbix (parenteses, pontos). Atualiza template/name e todas as referencias
# /Nome do Template/ dentro das expressoes de trigger.
DIR = File.dirname(File.expand_path(__FILE__))

RENAMES = {
  'APM Generic (summary) by HTTP'            => 'APM Generic summary by HTTP',
  'APM Generic (histogram) by HTTP'          => 'APM Generic histogram by HTTP',
  'APM Node.js (prom-client) by HTTP'        => 'APM NodeJS prom-client by HTTP',
  'APM Python (prometheus_client) by HTTP'   => 'APM Python prometheus-client by HTTP',
  'APM Go (client_golang) by HTTP'           => 'APM Go client-golang by HTTP',
  'APM .NET (prometheus-net) by HTTP'        => 'APM dotNET prometheus-net by HTTP',
  # 'APM Java Spring Boot by HTTP' ja e valido (sem caracteres especiais)
}

Dir.glob(File.join(DIR, '*.yaml')).sort.each do |path|
  t = File.read(path)
  changed = false
  RENAMES.each do |old, nw|
    if t.include?(old)
      t = t.gsub(old, nw)  # cobre template:, name:, description e /old/ nas expressoes
      changed = true
    end
  end
  if changed
    File.write(path, t)
    puts "atualizado: #{File.basename(path)}"
  else
    puts "sem mudanca: #{File.basename(path)}"
  end
end
