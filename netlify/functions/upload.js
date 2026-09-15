const { json, err, supabaseRequest, validateSession, generateJobId, calculatePrice, ALLOWED_EXTENSIONS, SUPABASE_URL, supabaseStorageUpload, parseMultipartFormData } = require('./_shared');

exports.handler = async (event) => {
  if (event.httpMethod === 'OPTIONS') return json({});

  const url = new URL(event.rawUrl);

  if (event.httpMethod === 'GET' && url.searchParams.get('action') === 'lookup-pin') {
    return handleLookupPin(url);
  }

  if (event.httpMethod !== 'POST') return err('Method not allowed', 405);

  return handleUpload(event);
};

async function handleLookupPin(url) {
  const pin = url.searchParams.get('pin') || '';
  if (!pin || !/^\d{6}$/.test(pin)) return err('Invalid PIN');

  const result = await supabaseRequest('GET', '/print_jobs', null, {
    'print_pin': 'eq.' + pin,
    'select': '*',
    'status': 'not.in.(completed,refund_issued)',
    'order': 'created_at.asc'
  });

  if (result.error || !result.data || result.data.length === 0) {
    return json({ success: false, 'error': 'No job found with this PIN' });
  }

  return json({ success: true, jobs: result.data, job: result.data[0] });
}

function getExtension(filename) {
  return (filename || '').split('.').pop().toLowerCase();
}

function isImage(ext) {
  return ['jpg', 'jpeg', 'png'].includes(ext);
}

async function handleUpload(event) {
  const session = await validateSession(event);
  const userId = session ? session.user_id : null;

  const contentType = event.headers['content-type'] || event.headers['Content-Type'] || '';

  let fileUrl = '';
  let fileName = '';
  let fileType = 'document';
  let colorMode = 'bw';
  let paperSize = 'a4';
  let sides = 'single';
  let copies = 1;
  let pages = 1;
  let brightness = 0;
  let contrast = 0;
  let cropMode = 'none';
  let finish = 'none';
  let printPin = '';

  const requestedPin = (value) => {
    const pin = String(value || '').trim();
    return /^\d{6}$/.test(pin) ? pin : '';
  };

  if (contentType.includes('multipart/form-data')) {
    let rawBody;
    if (event.isBase64Encoded) {
      rawBody = Buffer.from(event.body, 'base64');
    } else {
      rawBody = Buffer.from(event.body || '', 'binary');
    }

    const { fields, file } = parseMultipartFormData(rawBody, contentType);

    if (!file || !file.data || file.data.length === 0) {
      return err('No file uploaded');
    }

    const ext = getExtension(file.filename);
    if (!ALLOWED_EXTENSIONS.includes(ext)) {
      return err('File type not allowed. Allowed: ' + ALLOWED_EXTENSIONS.join(', '));
    }
    if (file.data.length > 20 * 1024 * 1024) {
      return err('File too large. Maximum 20MB.');
    }

    colorMode = ['bw', 'color'].includes(fields.color_mode) ? fields.color_mode : 'bw';
    paperSize = ['a4', 'a3'].includes(fields.paper_size) ? fields.paper_size : 'a4';
    sides = ['single', 'double'].includes(fields.sides) ? fields.sides : 'single';
    copies = Math.max(1, parseInt(fields.copies) || 1);
    pages = Math.max(1, parseInt(fields.pages) || 1);
    brightness = parseInt(fields.brightness) || 0;
    contrast = parseInt(fields.contrast) || 0;
    cropMode = fields.crop_mode || 'none';
    finish = fields.finish || 'none';
    printPin = requestedPin(fields.print_pin);

    const jobId = generateJobId();
    const safeName = 'print_' + Date.now() + '_' + (file.filename || 'file').replace(/[^a-zA-Z0-9._-]/g, '_');
    const storagePath = jobId + '/' + safeName;

    const storageResult = await supabaseStorageUpload('print-jobs', storagePath, file.data, file.contentType);
    if (storageResult.error) {
      return err('Failed to store file: ' + storageResult.message, 500);
    }

    fileUrl = SUPABASE_URL + '/storage/v1/object/public/print-jobs/' + storagePath;
    fileName = file.filename || 'upload.' + ext;
    fileType = isImage(ext) ? 'image' : 'document';

    if (!printPin) printPin = String(Math.floor(100000 + Math.random() * 900000));
    const jobData = {
      job_id: jobId,
      user_id: userId,
      session_id: require('crypto').randomBytes(16).toString('hex'),
      file_name: fileName,
      file_path: fileUrl,
      file_type: fileType,
      pages, color_mode: colorMode, paper_size: paperSize, sides, copies,
      brightness, contrast, crop_mode: cropMode, finish,
      total_price: calculatePrice(pages, colorMode, paperSize, sides, copies),
      status: 'pending',
      paid: false,
      print_pin: printPin
    };

    const result = await supabaseRequest('POST', '/print_jobs', jobData);
    if (result.error) {
      return err('Failed to create print job: ' + (result.message || ''), 500);
    }

    return json({
      success: true,
      job: result.data[0],
      print_pin: printPin,
      message: 'File uploaded successfully'
    });

  } else {
    let body;
    try {
      body = JSON.parse(event.body || '{}');
    } catch (e) {
      return err('Invalid request body');
    }

    fileUrl = body.file_url || '';
    fileName = body.file_name || '';
    const ext = getExtension(fileName);
    fileType = body.file_type || (isImage(ext) ? 'image' : 'document');

    if (!fileUrl || !fileName) return err('file_url and file_name required');
    if (!ALLOWED_EXTENSIONS.includes(ext)) return err('File type not allowed');
    if (fileType !== 'image' && fileType !== 'document') return err('file_type must be image or document');

    colorMode = ['bw', 'color'].includes(body.color_mode) ? body.color_mode : 'bw';
    paperSize = ['a4', 'a3'].includes(body.paper_size) ? body.paper_size : 'a4';
    sides = ['single', 'double'].includes(body.sides) ? body.sides : 'single';
    copies = Math.max(1, parseInt(body.copies) || 1);
    pages = Math.max(1, parseInt(body.pages) || 1);
    brightness = parseInt(body.brightness) || 0;
    contrast = parseInt(body.contrast) || 0;
    cropMode = body.crop_mode || 'none';
    finish = body.finish || 'none';
    printPin = requestedPin(body.print_pin);
    if (!printPin) printPin = String(Math.floor(100000 + Math.random() * 900000));

    const jobId = generateJobId();

    const jobData = {
      job_id: jobId,
      user_id: userId,
      session_id: require('crypto').randomBytes(16).toString('hex'),
      file_name: fileName,
      file_path: fileUrl,
      file_type: fileType,
      pages, color_mode: colorMode, paper_size: paperSize, sides, copies,
      brightness, contrast, crop_mode: cropMode, finish,
      total_price: calculatePrice(pages, colorMode, paperSize, sides, copies),
      status: 'pending',
      paid: false,
      print_pin: printPin
    };

    const result = await supabaseRequest('POST', '/print_jobs', jobData);
    if (result.error) {
      return err('Failed to create print job: ' + (result.message || ''), 500);
    }

    return json({
      success: true,
      job: result.data[0],
      print_pin: printPin,
      message: 'File uploaded successfully'
    });
  }
}
