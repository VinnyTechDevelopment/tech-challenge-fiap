# Roteiro do vídeo de demonstração (até 15 min)

> Upload no YouTube/Vimeo como **não listado**. Cada bloco cobre um item exigido no PDF da fase.
> As falas somam cerca de 10 minutos em ritmo normal (~1.400 palavras), sobrando tempo para a tela carregar. Adapte à sua forma de falar; ✏️ marca o que você precisa preencher.

## Resumo

| Tempo | Bloco | Requisito do PDF |
|---|---|---|
| 0:00–1:30 | Abertura e arquitetura | Arquitetura, divisão dos serviços, bancos |
| 1:30–2:30 | Repositórios | Repositório por serviço, `main` protegida |
| 2:30–7:30 | Fluxo completo de uma OS | Fluxo passando pelos microsserviços |
| 7:30–10:00 | Saga e falhas | Saga Pattern e tratamento de falhas |
| 10:00–12:30 | Deploy automatizado | Deploy com validação de testes |
| 12:30–14:30 | Observabilidade | Monitoramento e rastreamento distribuído |
| 14:30–15:00 | Encerramento | — |

## Antes de gravar

- Lab ligado, credenciais atualizadas e checagem feita (ver o passo a passo de retomada).
- Abas abertas: diagrama (`docs/Fase4/arquitetura.png`), GitHub da org, Bruno no ambiente **AWS**, Mercado Pago (janela anônima, comprador de teste logado), GitHub Actions, New Relic e um terminal com `kubectl`.
- Catálogo pronto: um serviço e uma peça com **estoque 3** (para o fluxo feliz) e saber que pedir 5 unidades força a falta de peças.
- Um PR pequeno pronto no billing ou no execution para o bloco de deploy (ex.: uma linha no README).

---

## 0:00–1:30 · Abertura e arquitetura

**Tela:** diagrama `docs/Fase4/arquitetura.png`.

> Olá! Eu sou ✏️ e este é o Tech Challenge da Fase 4 da Pós Tech de Arquitetura de Software da FIAP.
>
> Nesta fase a oficina mecânica passou a operar em escala nacional, e a aplicação foi refatorada de um monólito para três microsserviços independentes, cada um com o próprio repositório, a própria infraestrutura e o próprio banco de dados.
>
> O **os-service** cuida das ordens de serviço, dos clientes e dos veículos, e é o orquestrador da Saga. Ele usa MySQL.
> O **billing-service** cuida do orçamento e do pagamento, integrado ao Mercado Pago. Tem o seu próprio MySQL.
> E o **execution-service** cuida do catálogo, do estoque e da fila de execução da oficina. Ele usa DynamoDB, que é o nosso banco NoSQL.
>
> Nenhum serviço acessa o banco de outro. A comunicação acontece de duas formas: REST síncrono quando a resposta precisa ser imediata, como na consulta de preços do catálogo ao abrir a OS, e mensageria assíncrona com Amazon SQS para todos os passos da Saga.
>
> Tudo roda na AWS: os três serviços no EKS, atrás de um API Gateway com um Lambda authorizer, e a observabilidade continua no New Relic, que já usávamos na Fase 3.
>
> Para a Saga escolhemos a **orquestração**: o os-service conduz o fluxo — orçamento, aprovação, pagamento, execução — e dispara as compensações quando algo falha. Escolhemos orquestrar porque o fluxo é linear e cada etapa tem uma compensação específica; com um orquestrador, a regra fica explícita num lugar só, fica fácil de testar e de rastrear por OS. A justificativa completa está no ADR do repositório.

## 1:30–2:30 · Repositórios

**Tela:** org `VinnyTechDevelopment` no GitHub → um dos repositórios → Settings → Branches.

> Aqui estão os repositórios. Os três microsserviços — `tech-challenge-fiap`, que é o os-service, `billing-service-fiap` e `execution-service-fiap` — e os repositórios de infraestrutura compartilhada: o cluster EKS, o banco do os-service e o API Gateway com a Lambda de autenticação por CPF.
>
> Cada microsserviço tem o seu código, Dockerfile, manifestos Kubernetes, o Terraform da própria infraestrutura, a documentação OpenAPI e os pipelines de CI/CD.
>
> A branch `main` é protegida em todos eles: não dá para fazer push direto, toda mudança entra por pull request, e o merge só é liberado com os checks passando — lint, testes com cobertura mínima de 80%, análise do SonarCloud, validação dos manifestos e build da imagem.

## 2:30–7:30 · Fluxo completo de uma OS

**Tela:** Bruno (ambiente AWS) e, em paralelo, a resposta de **Detalhar OS**.

**1. Login e abertura da OS**

> Vou fazer o fluxo completo de uma ordem de serviço passando pelos três microsserviços, tudo pelo API Gateway na AWS.
>
> Primeiro, o atendente faz login no os-service e recebe um JWT. Esse mesmo token é aceito pelos outros serviços e pelo authorizer do gateway.
>
> Agora abro a OS para um cliente e um veículo, com um serviço de revisão e duas unidades de filtro de óleo. Repare que o os-service respondeu **202 Accepted**: a OS foi registrada e a Saga começou, mas o resto do fluxo acontece de forma assíncrona. O preço que aparece aqui veio do execution-service, numa chamada REST síncrona ao catálogo.

**2. Orçamento e aprovação**

> O os-service publicou um comando no SQS pedindo o orçamento ao billing-service. Se eu consultar a OS agora, ela já está em **aguardando aprovação**, e a saga traz o link de aprovação que o cliente recebe por e-mail.
>
> Vou abrir esse link como se fosse o cliente. O billing registra a aprovação e cria a cobrança no Mercado Pago. A OS passa para **aguardando pagamento**.

**3. Pagamento**

> Agora o cliente paga pelo Mercado Pago, aqui com um cartão de teste no ambiente sandbox.
>
> Quando o pagamento é aprovado, o Mercado Pago chama o webhook do billing. O billing valida a assinatura da notificação, consulta o pagamento na API do Mercado Pago para confirmar, e avisa a Saga.

**4. Execução**

> Com o pagamento confirmado, o os-service manda o execution-service reservar as peças e colocar a OS na fila. A reserva no DynamoDB é atômica: ou todas as peças são reservadas, ou nenhuma. A OS está **aguardando execução**, ela aparece na fila, e o estoque do filtro caiu de 3 para 1.
>
> Agora o mecânico inicia o diagnóstico... depois o reparo... e finaliza. A cada passo o execution-service publica um evento, e o os-service atualiza o status: **em diagnóstico**, **em execução** e **finalizada**.

**5. Entrega e histórico**

> Por fim, o atendente registra a entrega do veículo.
>
> No histórico da OS dá para ver todas as transições de status, e também cada passo da Saga: o orçamento, a aprovação, o pagamento, a reserva das peças e a execução. A Saga terminou no estado **COMPLETED**.

## 7:30–10:00 · Saga e tratamento de falhas

**Tela:** Bruno → nova OS pedindo 5 unidades da peça; depois o histórico; depois o código do orquestrador e o arquivo BDD.

> Agora o mais importante: o que acontece quando algo dá errado no meio do caminho.
>
> Vou abrir uma nova OS pedindo cinco unidades do filtro, mas só tem uma em estoque. O cliente aprova e paga normalmente — nesse ponto, o dinheiro já foi cobrado.
>
> Quando o execution-service tenta reservar as peças, a reserva falha por falta de estoque. Ele responde com uma falha, e o orquestrador entra em **compensação**: manda o billing-service **estornar o pagamento** no Mercado Pago. Só quando o estorno é confirmado a OS é cancelada, com o motivo registrado.
>
> No histórico da saga dá para ver exatamente isso: a reserva falhou, a compensação de estorno começou e foi concluída, e a saga terminou como cancelada. O pagamento consta como estornado no billing, e o estoque continua intacto, porque a reserva atômica não baixou nada.
>
> As outras falhas também têm compensação: se o cliente recusa o orçamento, a OS é cancelada; se o pagamento é recusado ou o prazo expira, o orçamento é cancelado; e se o atendente cancela durante a execução, as peças voltam ao estoque e o pagamento é estornado — as duas compensações em paralelo.
>
> Para garantir consistência usamos o padrão **outbox transacional**: o novo estado e a mensagem são gravados na mesma transação do banco, então nunca existe estado salvo sem a mensagem correspondente. As mensagens são processadas uma única vez, mesmo com reentrega do SQS, e o que falha cinco vezes vai para uma fila de erros, a DLQ.
>
> Este é o orquestrador no código, e este é o teste BDD em português que descreve o fluxo completo e as compensações — ele roda em todo pull request.

## 10:00–12:30 · Deploy automatizado com validação de testes

**Tela:** PR pequeno no billing (ou execution) → checks → merge → Actions → `kubectl get pods`.

> Agora o deploy automatizado. Vou abrir um pull request com uma mudança pequena no billing-service.
>
> O pipeline roda sozinho: lint com o Pint, testes unitários e de integração com o PHPUnit — aqui está o resumo de cobertura, acima de 95%, e o pipeline reprova se ficar abaixo de 80% —, a análise do SonarCloud com quality gate, a validação dos manifestos Kubernetes e o build da imagem.
>
> Com tudo verde, faço o merge. O merge na `main` dispara o build da imagem, que é publicada no GitHub Container Registry, e em seguida o deploy: o Terraform aplica a nova versão no EKS — roda as migrations, atualiza a API e o worker.
>
> No cluster, os pods novos já estão no ar com a nova versão, sem nenhuma ação manual.

## 12:30–14:30 · Monitoramento e rastreamento distribuído

**Tela:** New Relic → Distributed tracing → um trace de OS; dashboard "Saga de Ordens de Serviço"; Kubernetes; Logs.

> A observabilidade continua no New Relic, que já usávamos na Fase 3, agora ligada nos três microsserviços.
>
> Este é um **trace distribuído** de uma ordem de serviço. Ele começa na abertura da OS no os-service, e continua no billing e no execution. Isso funciona porque os cabeçalhos de rastreamento viajam dentro das próprias mensagens do SQS, então o New Relic consegue ligar as etapas de serviços diferentes num único fluxo.
>
> Este é o dashboard da Saga, criado como código pelo Terraform: quantas sagas foram concluídas e quantas canceladas, os passos por resultado — inclusive as compensações —, a latência do processamento das mensagens e as falhas que vão para a DLQ.
>
> Aqui está a visão do Kubernetes, com os pods dos três namespaces, e aqui os logs de cada serviço, ligados aos traces.

## 14:30–15:00 · Encerramento

> Resumindo: dividimos a aplicação em três microsserviços com bancos próprios, SQL e NoSQL; coordenamos o fluxo da OS com uma Saga orquestrada, com compensações em cada etapa; cada serviço tem pipeline próprio com testes, cobertura acima de 80%, análise de qualidade e deploy automatizado no Kubernetes; e acompanhamos tudo pelo New Relic.
>
> Os links dos repositórios e a documentação estão no PDF de entrega. Obrigado!

---

## Plano B (se o Mercado Pago não colaborar na hora)

- Mostre a integração real até o link de checkout do Mercado Pago e, para o restante do fluxo, troque temporariamente o billing para `PAYMENT_GATEWAY=fake`: o link de pagamento vira o simulador (`Simular pagamento` no Bruno, `approved` ou `rejected`). Explique: *"a integração com o Mercado Pago está no código e nos testes; aqui usamos o simulador para demonstrar a Saga sem depender do sandbox"*.
- Local, sem AWS: `docker compose -f docker-compose.microservices.yml up -d` e `php scripts/e2e-saga.php` executam e narram os dois cenários (fluxo feliz e falta de peças).
