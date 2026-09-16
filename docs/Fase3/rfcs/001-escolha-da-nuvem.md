# RFC 001 — Escolha da nuvem

- **Status:** Aceito
- **Autores:** Equipe POS Tech
- **Contexto temporal:** Fase 3 do Tech Challenge (SOAT)

## Contexto

A Fase 3 exige migrar a aplicação (até então rodando em Minikube local) para uma infraestrutura
corporativa: API Gateway, Function Serverless, banco gerenciado e cluster Kubernetes com
escalabilidade, tudo como código (Terraform).

## Alternativas consideradas

| Opção | Prós | Contras |
|---|---|---|
| **AWS** (escolhida) | Suporte completo a EKS, RDS, Lambda, API Gateway; conta de estudo disponível via **AWS Academy Learner Lab**, sem custo para o grupo | AWS Academy tem restrições fortes (ver abaixo) |
| GCP (GKE + Cloud SQL + Cloud Functions) | Sem as restrições de uma conta de laboratório | Sem crédito/conta gratuita já disponível para o grupo; curva de aprendizado adicional |
| Azure (AKS + Azure SQL + Functions) | Idem GCP | Idem GCP |

## Decisão

**AWS**, usando uma conta do **AWS Academy Learner Lab**.

## Consequências (a parte que mais moldou o resto do desenho)

O Learner Lab impõe restrições que aparecem espalhadas por toda a infraestrutura — vale registrar
juntas aqui para não parecerem decisões arbitrárias em cada repositório:

- **Não é possível criar roles IAM novas.** Só existe a `LabRole` pré-provisionada pela Academy.
  Por isso `infra-kubernetes-fiap/eks.tf` usa `create_iam_role = false` / `iam_role_arn =
  var.lab_role_arn` no cluster e nos node groups, e `lambda-auth-cpf-fiap/lambda.tf` usa
  `role = var.lab_role_arn` na Lambda, em vez de deixar o Terraform criar roles dedicadas.
- **Não é possível criar um IAM OIDC provider**, o que desabilita IRSA (`enable_irsa = false`) e a
  autenticação via OIDC nos workflows do GitHub Actions — por isso os workflows de `terraform apply`
  usam Access Key/Secret/Session Token temporários (secrets do repositório), não uma role assumida.
- **Credenciais expiram em poucas horas** (sessão do Lab). Por isso o `apply` das infras é sempre
  manual (`workflow_dispatch`), nunca automático a cada push — automatizar destravaria só numa conta
  AWS "de verdade" fora do Academy (ver notas nos READMEs de `infra-kubernetes-fiap`/`infra-database-fiap`).
- **Sem permissão para criar KMS keys.** Por isso `create_kms_key = false` / `cluster_encryption_config
  = {}` no EKS — a criptografia de secrets do cluster fica no padrão do EKS, não numa KMS key própria.
- **RDS sem Enhanced Monitoring/Performance Insights**, porque essas duas features do RDS exigem uma
  IAM role própria (`monitoring_role_arn`) que o Academy não libera.

Se o projeto migrar para uma conta AWS "normal" no futuro, todas essas restrições podem ser
revertidas (roles IAM próprias, IRSA, OIDC no CI, KMS, RDS Enhanced Monitoring) sem mudar a escolha
de nuvem em si — são flags isoladas nos módulos Terraform, não decisões estruturais.
