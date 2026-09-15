const { isValidCPF, onlyDigits } = require('../src/cpf');

describe('isValidCPF', () => {
  it.each(['52998224725', '529.982.247-25'])('aceita um CPF válido (%s)', (cpf) => {
    expect(isValidCPF(cpf)).toBe(true);
  });

  it.each(['11111111111', '12345678900', '123', '', null, undefined])(
    'rejeita CPF inválido (%s)',
    (cpf) => {
      expect(isValidCPF(cpf)).toBe(false);
    }
  );
});

describe('onlyDigits', () => {
  it('remove caracteres não numéricos', () => {
    expect(onlyDigits('529.982.247-25')).toBe('52998224725');
  });
});
