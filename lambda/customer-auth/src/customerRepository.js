const mysql = require('mysql2/promise');

let pool;

function getPool() {
  if (!pool) {
    pool = mysql.createPool({
      host: process.env.DB_HOST,
      port: Number(process.env.DB_PORT || 3306),
      user: process.env.DB_USER,
      password: process.env.DB_PASSWORD,
      database: process.env.DB_NAME,
      waitForConnections: true,
      connectionLimit: 2,
      maxIdle: 2,
      idleTimeout: 30000,
    });
  }

  return pool;
}

async function findCustomerByDocument(document) {
  const [rows] = await getPool().query(
    'SELECT id, name, status FROM customers WHERE document = ? LIMIT 1',
    [document]
  );

  return rows[0] ?? null;
}

module.exports = { findCustomerByDocument };
