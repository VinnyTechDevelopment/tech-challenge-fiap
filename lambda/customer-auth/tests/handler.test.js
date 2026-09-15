jest.mock('../src/customerRepository');
jest.mock('../src/token');

const { handler } = require('../src/handler');
const { findCustomerByDocument } = require('../src/customerRepository');
const { issueCustomerToken } = require('../src/token');

const VALID_CPF = '52998224725';

function buildEvent(body) {
  return { body: JSON.stringify(body) };
}

beforeEach(() => {
  jest.resetAllMocks();
});

test('retorna 400 quando document não é enviado', async () => {
  const response = await handler(buildEvent({}));
  expect(response.statusCode).toBe(400);
});

test('retorna 422 para CPF com formato/checksum inválido', async () => {
  const response = await handler(buildEvent({ document: '11111111111' }));
  expect(response.statusCode).toBe(422);
  expect(findCustomerByDocument).not.toHaveBeenCalled();
});

test('retorna 404 quando cliente não existe', async () => {
  findCustomerByDocument.mockResolvedValue(null);
  const response = await handler(buildEvent({ document: VALID_CPF }));
  expect(response.statusCode).toBe(404);
});

test('retorna 403 quando cliente está inativo', async () => {
  findCustomerByDocument.mockResolvedValue({ id: 'c1', status: 'inactive' });
  const response = await handler(buildEvent({ document: VALID_CPF }));
  expect(response.statusCode).toBe(403);
});

test('retorna 200 com token quando cliente está ativo', async () => {
  findCustomerByDocument.mockResolvedValue({ id: 'c1', status: 'active' });
  issueCustomerToken.mockReturnValue('signed.jwt.token');

  const response = await handler(buildEvent({ document: VALID_CPF }));
  const body = JSON.parse(response.body);

  expect(response.statusCode).toBe(200);
  expect(body.token).toBe('signed.jwt.token');
  expect(issueCustomerToken).toHaveBeenCalledWith({ id: 'c1', document: VALID_CPF });
});

test('retorna 500 quando a consulta ao banco falha', async () => {
  findCustomerByDocument.mockRejectedValue(new Error('conn refused'));
  const response = await handler(buildEvent({ document: VALID_CPF }));
  expect(response.statusCode).toBe(500);
});
