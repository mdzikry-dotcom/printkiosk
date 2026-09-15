const { json, err, supabaseRequest, validateSession, generateJobId, calculatePrice, ALLOWED_EXTENSIONS } = require('./_shared');

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
    'status': 'not.in.(completed,refund_issued)'
  });

  if (result.error || !result.data || result.data.length === 0) {
    return json({ success: false, 'error': 'No job found with this PIN' });
  }

  const job = result.data[0];
  return json({ success: true, job });
}

async function handleUpload(event) {
  const session = await validateSession(event);
  const userId = session ? session.user_id : null;

  let body;
  const contentType = event.headers['content-type'] || event.headers['Content-Type'] || '';

  if (contentType.includes('multipart/form-data')) {
    return err('File uploads must use Supabase Storage. Upload file to storage, then submit metadata here.', 400);
  }

  try {
    body = JSON.parse(event.body || '{}');
  } catch (e) {
    return err('Invalid request body');
  }

  const fileUrl = body.file_url || '';
  const fileName = body.file_name || '';
  const fileType = body.file_type || 'document';
  const ext = fileName.split('.').pop().toLowerCase();

  if (!fileUrl || !fileName) return err('file_url and file_name required');
  if (!ALLOWED_EXTENSIONS.includes(ext)) return err('File type not allowed');
  if (fileType !== 'image' && fileType !== 'document') return err('file_type must be image or document');

  const colorMode = ['bw', 'color'].includes(body.color_mode) ? body.color_mode : 'bw';
  const paperSize = ['a4', 'a3'].includes(body.paper_size) ? body.paper_size : 'a4';
  const sides = ['single', 'double'].includes(body.sides) ? body.sides : 'single';
  const copies = Math.max(1, parseInt(body.copies) || 1);
  const pages = Math.max(1, parseInt(body.pages) || 1);
  const brightness = parseInt(body.brightness) || 0;
  const contrast = parseInt(body.contrast) || 0;
  const cropMode = body.crop_mode || 'none';
  const finish = body.finish || 'none';

  const totalPrice = calculatePrice(pages, colorMode, paperSize, sides, copies);
  const printPin = String(Math.floor(100000 + Math.random() * 900000));
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
    total_price: totalPrice,
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
