#!/usr/bin/env ruby
# Gera templates APM por stack a partir do template generico (summary ou histogram),
# substituindo nome do template, defaults de macros e UUIDs.
# Uso: ruby .generate.rb
require 'yaml'
require 'digest'

DIR = File.dirname(File.expand_path(__FILE__))
SUMMARY = YAML.load_file(File.join(DIR, 'apm_generic_summary_by_http.yaml'))
HIST    = YAML.load_file(File.join(DIR, 'apm_generic_histogram_by_http.yaml'))

# Deriva UUID deterministico (32 hex) a partir de uma seed, para nao colidir entre templates.
def uuidify(obj, salt)
  case obj
  when Hash
    obj.each { |k, v| obj[k] = (k == 'uuid' ? Digest::MD5.hexdigest(v.to_s + salt) : uuidify(v, salt)) }
    obj
  when Array
    obj.map { |e| uuidify(e, salt) }
  else
    obj
  end
end

def set_macro(tpl, name, value)
  m = tpl['macros'].find { |x| x['macro'] == name }
  m['value'] = value if m
end

# Renomeia o template e reescreve o nome dele nas expressoes de trigger.
def rename(doc, old_name, new_name)
  tpl = doc['zabbix_export']['templates'][0]
  tpl['template'] = new_name
  tpl['name'] = new_name
  # triggers globais
  (doc['zabbix_export']['triggers'] || []).each do |t|
    t['expression'] = t['expression'].gsub("/#{old_name}/", "/#{new_name}/")
  end
  # trigger prototypes dentro das LLD
  (tpl['discovery_rules'] || []).each do |r|
    (r['trigger_prototypes'] || []).each do |t|
      t['expression'] = t['expression'].gsub("/#{old_name}/", "/#{new_name}/")
    end
  end
end

STACKS = [
  # arquivo, base, nome novo, defaults {macro=>valor}
  ['apm_java_spring_by_http.yaml', :summary, 'APM Java Spring Boot by HTTP', {
    '{$APM.URL}' => 'http://localhost:8080/actuator/prometheus',
    '{$APM.METRIC.REQUESTS}' => 'http_server_requests_seconds_count',
    '{$APM.METRIC.LATENCY}'  => 'http_server_requests_seconds',
    '{$APM.LABEL.URI}' => 'uri', '{$APM.LABEL.METHOD}' => 'method', '{$APM.LABEL.STATUS}' => 'status',
    '{$APM.LLD.URI.NOT_MATCHES}' => '^(/actuator.*|/favicon.ico)$'
  }],
  ['apm_nodejs_promclient_by_http.yaml', :histogram, 'APM Node.js (prom-client) by HTTP', {
    '{$APM.URL}' => 'http://localhost:3000/metrics',
    '{$APM.METRIC.LATENCY}' => 'http_request_duration_seconds',
    '{$APM.LABEL.URI}' => 'route', '{$APM.LABEL.METHOD}' => 'method', '{$APM.LABEL.STATUS}' => 'code',
    '{$APM.LLD.URI.NOT_MATCHES}' => '^(/metrics|/favicon.ico)$'
  }],
  ['apm_python_prometheus_by_http.yaml', :histogram, 'APM Python (prometheus_client) by HTTP', {
    '{$APM.URL}' => 'http://localhost:8000/metrics',
    '{$APM.METRIC.LATENCY}' => 'http_request_duration_seconds',
    '{$APM.LABEL.URI}' => 'handler', '{$APM.LABEL.METHOD}' => 'method', '{$APM.LABEL.STATUS}' => 'status',
    '{$APM.LLD.URI.NOT_MATCHES}' => '^(/metrics|/favicon.ico)$'
  }],
  ['apm_go_client_by_http.yaml', :histogram, 'APM Go (client_golang) by HTTP', {
    '{$APM.URL}' => 'http://localhost:2112/metrics',
    '{$APM.METRIC.LATENCY}' => 'http_request_duration_seconds',
    '{$APM.LABEL.URI}' => 'path', '{$APM.LABEL.METHOD}' => 'method', '{$APM.LABEL.STATUS}' => 'code',
    '{$APM.LLD.URI.NOT_MATCHES}' => '^(/metrics|/favicon.ico)$'
  }],
  ['apm_dotnet_prometheus_by_http.yaml', :histogram, 'APM .NET (prometheus-net) by HTTP', {
    '{$APM.URL}' => 'http://localhost:5000/metrics',
    '{$APM.METRIC.LATENCY}' => 'http_request_duration_seconds',
    '{$APM.LABEL.URI}' => 'controller', '{$APM.LABEL.METHOD}' => 'method', '{$APM.LABEL.STATUS}' => 'code',
    '{$APM.LLD.URI.NOT_MATCHES}' => '^(/metrics|/favicon.ico)$'
  }],
]

STACKS.each do |file, base, new_name, macros|
  src = base == :summary ? SUMMARY : HIST
  old_name = src['zabbix_export']['templates'][0]['name']
  doc = Marshal.load(Marshal.dump(src)) # deep copy
  uuidify(doc, file)                    # UUIDs unicos por arquivo
  rename(doc, old_name, new_name)
  tpl = doc['zabbix_export']['templates'][0]
  macros.each { |k, v| set_macro(tpl, k, v) }
  File.write(File.join(DIR, file), doc.to_yaml(line_width: -1))
  puts "gerado: #{file}  (base: #{base})"
end
