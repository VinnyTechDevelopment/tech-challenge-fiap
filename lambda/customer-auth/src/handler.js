const { isValidCPF, onlyDigits } = require('./cpf');
const { findCustomerByDocument } = require('./customerRepository');
const { issueCustomerToken } = require('./token');
const { jsonResponse } = require('./httpResponse');

const ACTIVE_STATUS = 'active';

function extractDocument(event) {
  if (event.body) {
    const body = typeof event.body === 'string' ? JSON.parse(event.body) : event.body;
    if (body.document || body.cpf) {
      return body.document || body.cpf;
    }
  }

  return event.queryStringParameters?.document ?? event.queryStringParameters?.cpf;
}

async function handler(event) {
  let rawDocument;

  try {
    rawDocument = extractDocument(event);
  } catch (error) {
    return jsonResponse(400, { message: 'Corpo da requisição inválido.' });
  }

  if (!rawDocument) {
    return jsonResponse(400, { message: 'O campo "document" (CPF) é obrigatório.' });
  }

  const document = onlyDigits(rawDocument);

  if (!isValidCPF(document)) {
    return jsonResponse(422, { message: 'CPF inválido.' });
  }

  let customer;
  try {
    customer = await findCustomerByDocument(document);
  } catch (error) {
    console.error('Falha ao consultar cliente na base de dados', error);
    return jsonResponse(500, { message: 'Erro interno ao consultar o cliente.' });
  }

  if (!customer) {
    return jsonResponse(404, { message: 'Cliente não encontrado.' });
  }

  if (customer.status !== ACTIVE_STATUS) {
    return jsonResponse(403, { message: 'Cliente inativo. Procure o atendimento.' });
  }

  const token = issueCustomerToken({ id: customer.id, document });

  return jsonResponse(200, {
    token,
    tokenType: 'Bearer',
    expiresIn: Number(process.env.TOKEN_TTL_SECONDS || 3600),
  });
}

module.exports = { handler };
