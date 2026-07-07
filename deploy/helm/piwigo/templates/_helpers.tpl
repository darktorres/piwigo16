{{- define "piwigo.name" -}}
{{- .Chart.Name -}}
{{- end -}}

{{- define "piwigo.fullname" -}}
{{- printf "%s-%s" .Release.Name .Chart.Name | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "piwigo.labels" -}}
app.kubernetes.io/name: {{ include "piwigo.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: {{ .Chart.Name }}-{{ .Chart.Version }}
{{- end -}}

{{- define "piwigo.selectorLabels" -}}
app.kubernetes.io/name: {{ include "piwigo.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}
