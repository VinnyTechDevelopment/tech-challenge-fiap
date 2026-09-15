function onlyDigits(value) {
  return String(value ?? '').replace(/\D/g, '');
}

function isValidCPF(rawCpf) {
  const cpf = onlyDigits(rawCpf);

  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
    return false;
  }

  for (let t = 9; t < 11; t++) {
    let sum = 0;
    for (let c = 0; c < t; c++) {
      sum += Number(cpf[c]) * (t + 1 - c);
    }
    const digit = ((10 * sum) % 11) % 10;
    if (Number(cpf[t]) !== digit) {
      return false;
    }
  }

  return true;
}

module.exports = { isValidCPF, onlyDigits };
