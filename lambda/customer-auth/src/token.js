const jwt = require('jsonwebtoken');

function issueCustomerToken({ id, document }) {
  const ttlSeconds = Number(process.env.TOKEN_TTL_SECONDS || 3600);

  return jwt.sign(
    {
      sub: id,
      document,
      type: 'customer',
    },
    process.env.CUSTOMER_JWT_SECRET,
    { expiresIn: ttlSeconds, algorithm: 'HS256' }
  );
}

module.exports = { issueCustomerToken };
