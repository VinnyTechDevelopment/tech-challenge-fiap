# ADR 002 — Uso de HorizontalPodAutoscaler (HPA)

- **Status:** Aceito

## Contexto

A Fase 3 pede "Cluster Kubernetes com escalabilidade". O `Deployment` da aplicação
(`kubernetes_deployment_v1.app`, em `infra/app.tf`) já roda com `replicas = 2` fixo; era preciso
decidir o mecanismo de escala.

## Decisão

**HorizontalPodAutoscaler nativo do Kubernetes** (`kubernetes_horizontal_pod_autoscaler_v2.app`),
escalando o `Deployment` `postech-app` entre **2 e 10 réplicas**, por duas métricas de recurso:

- CPU: alvo de **70%** de utilização média
- Memória: alvo de **80%** de utilização média

Com política de comportamento assimétrica:

- **Scale up:** janela de estabilização de 60s, adiciona até 2 pods a cada 60s (`select_policy = "Max"`)
- **Scale down:** janela de estabilização de 300s, remove no máximo 1 pod a cada 120s

## Alternativas consideradas

| Opção | Por que não |
|---|---|
| **KEDA** (escala por métricas externas/customizadas, ex.: fila) | Não há fila nem métrica de negócio (ex.: OS pendentes) que justifique escalar por algo além de CPU/memória hoje; adicionaria um operator extra ao cluster sem necessidade concreta |
| **VerticalPodAutoscaler (VPA)** | Resolve um problema diferente (redimensionar requests/limits de um pod), não "mais réplicas sob carga"; os dois não são mutuamente exclusivos, mas VPA sozinho não atende "escalabilidade" no sentido de aguentar mais tráfego simultâneo |
| **Escala manual (`kubectl scale` / replicas fixas maiores)** | Não é autoscaling de verdade — desperdiça recursos em baixa demanda ou fica subdimensionado em pico, e não atende o requisito da fase |

## Por que scale-down mais lento que scale-up

Assimetria deliberada: é mais barato ter um pod a mais rodando por alguns minutos do que derrubar um
pod cedo demais e sofrer novo scale-up (com cold start da aplicação Laravel) logo em seguida se o
tráfego for só um pico passageiro. A janela de 300s de estabilização no scale-down existe justamente
para isso.

## Consequências

- **Dependência direta do `metrics-server`**, que passou a ser provisionado **uma única vez** no
  repositório `infra-kubernetes-fiap` (não mais por aplicação, como no Minikube da Fase 2) — se o
  HPA reclamar de "unknown metrics" logo após o primeiro `apply`, é só uma questão de tempo até o
  metrics-server publicar as métricas, não um erro de configuração (comentário deixado no próprio
  `infra/app.tf` para quem for debugar isso depois).
- `min_replicas = 2` (não 1): garante que o Service sempre tenha pelo menos duas réplicas, mesmo em
  repouso — tolerância a falha de um pod sem downtime, não só escala sob carga.
- O HPA escala o `Deployment` da aplicação; o `Job` de migration (`kubernetes_job_v1.migrate`) não é
  afetado — migrations continuam rodando uma única vez por deploy, fora do autoscaling.
