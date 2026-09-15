const DEFAULT_HEADERS = {
  'Content-Type': 'application/json',
};

function jsonResponse(statusCode, body) {
  return {
    statusCode,
    headers: DEFAULT_HEADERS,
    body: JSON.stringify(body),
  };
}

module.exports = { jsonResponse };
