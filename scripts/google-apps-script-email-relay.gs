function doPost(event) {
  const payload = parsePayload(event);
  if (!payload) {
    return jsonResponse({ ok: false, error: 'invalid_request' });
  }

  const expectedSecret = PropertiesService.getScriptProperties().getProperty('RELAY_SECRET') || '';
  if (!/^[a-f0-9]{64}$/i.test(expectedSecret)
      || typeof payload.secret !== 'string'
      || !constantTimeEquals(payload.secret, expectedSecret)) {
    return jsonResponse({ ok: false, error: 'unauthorized' });
  }

  if (typeof payload.to !== 'string'
      || typeof payload.subject !== 'string'
      || typeof payload.text !== 'string'
      || typeof payload.html !== 'string') {
    return jsonResponse({ ok: false, error: 'invalid_message' });
  }

  const recipient = payload.to.trim();
  const subject = payload.subject;
  const text = payload.text;
  const html = payload.html;
  if (!isValidEmail(recipient)
      || !subject
      || /[\r\n]/.test(subject)
      || subject.length > 200
      || (!text && !html)
      || Utilities.newBlob(text + html).getBytes().length > 180000) {
    return jsonResponse({ ok: false, error: 'invalid_message' });
  }

  const options = { name: 'Stockwell' };
  if (html) {
    options.htmlBody = html;
  }

  try {
    MailApp.sendEmail(recipient, subject, text || 'Please view this message in an HTML-capable email client.', options);
    return jsonResponse({ ok: true });
  } catch (error) {
    console.error('Stockwell email relay failed to send a message.');
    return jsonResponse({ ok: false, error: 'send_failed' });
  }
}

function parsePayload(event) {
  if (!event || !event.postData || typeof event.postData.contents !== 'string'
      || event.postData.contents.length > 200000) {
    return null;
  }

  try {
    const payload = JSON.parse(event.postData.contents);
    return payload && typeof payload === 'object' && !Array.isArray(payload) ? payload : null;
  } catch (error) {
    return null;
  }
}

function isValidEmail(email) {
  return email.length <= 254
    && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email);
}

function constantTimeEquals(actual, expected) {
  const actualBytes = Utilities.computeDigest(
    Utilities.DigestAlgorithm.SHA_256,
    actual,
    Utilities.Charset.UTF_8
  );
  const expectedBytes = Utilities.computeDigest(
    Utilities.DigestAlgorithm.SHA_256,
    expected,
    Utilities.Charset.UTF_8
  );
  let difference = 0;
  for (let index = 0; index < actualBytes.length; index += 1) {
    difference |= actualBytes[index] ^ expectedBytes[index];
  }
  return difference === 0;
}

function jsonResponse(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}
