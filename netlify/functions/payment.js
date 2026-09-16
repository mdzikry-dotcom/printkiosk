const { json, err, supabaseRequest } = require('./_shared');

exports.handler = async (event) => {
  if (event.httpMethod === 'OPTIONS') return json({});

  if (event.httpMethod !== 'POST') return err('Method not allowed', 405);

  const url = new URL(event.rawUrl);
  const action = url.searchParams.get('action');

  switch (action) {
    case 'confirm': return handlePaymentConfirm(event);
    case 'simulate': return handlePaymentSimulate(event);
    case 'check-status': return handleCheckStatus(event);
    default: return err('Invalid action');
  }
};

async function handlePaymentConfirm(event) {
  const body = JSON.parse(event.body || '{}');
  const jobId = body.job_id || '';
  const reference = body.reference || 'PAY-' + require('crypto').randomBytes(8).toString('hex').toUpperCase();

  if (!jobId) return err('Job ID required');

  const jobResult = await supabaseRequest('GET', '/print_jobs', null, {
    'job_id': 'eq.' + jobId,
    'select': '*'
  });

  if (jobResult.error || !jobResult.data || jobResult.data.length === 0) {
    return err('Print job not found', 404);
  }

  const job = jobResult.data[0];
  if (job.paid) return json({ success: true, message: 'Print job already paid', job });

  const paymentResult = await supabaseRequest('POST', '/payments', {
    print_job_id: job.id,
    user_id: job.user_id,
    amount: job.total_price,
    method: 'maybank_qr',
    reference,
    status: 'confirmed',
    notified_at: new Date().toISOString()
  });

  if (paymentResult.error) return err('Failed to create payment record', 500);

  const updateResult = await supabaseRequest('PATCH', '/print_jobs?id=eq.' + job.id, {
    status: 'ready_to_print',
    paid: true,
    payment_ref: reference,
    updated_at: new Date().toISOString()
  });

  if (updateResult.error) return err('Failed to update job status', 500);

  let coinsEarned = 0;
  if (job.user_id) {
    coinsEarned = 1;

    const userResult = await supabaseRequest('GET', '/users', null, {
      'id': 'eq.' + job.user_id,
      'select': 'coins'
    });

    if (!userResult.error && userResult.data && userResult.data.length > 0) {
      const currentCoins = userResult.data[0].coins;
      const newBalance = currentCoins + coinsEarned;

      await supabaseRequest('PATCH', '/users?id=eq.' + job.user_id, { coins: newBalance });
      await supabaseRequest('POST', '/coin_transactions', {
        user_id: job.user_id,
        print_job_id: job.id,
        type: 'earned',
        amount: coinsEarned,
        description: 'Print job ' + job.job_id,
        balance_after: newBalance
      });
    }
  }

  await supabaseRequest('POST', '/alerts', {
    type: 'payment_received',
    message: 'Payment received for job ' + job.job_id + ' - RM ' + Number(job.total_price).toFixed(2),
    severity: 'info',
    is_read: false
  });

  return json({
    success: true,
    message: 'Payment confirmed',
    job_id: jobId,
    reference,
    coins_earned: coinsEarned
  });
}

async function handlePaymentSimulate(event) {
  const body = JSON.parse(event.body || '{}');
  const jobId = body.job_id || '';

  if (!jobId) return err('Job ID required');

  const jobResult = await supabaseRequest('GET', '/print_jobs', null, {
    'job_id': 'eq.' + jobId,
    'select': '*'
  });

  if (jobResult.error || !jobResult.data || jobResult.data.length === 0) {
    return err('Print job not found', 404);
  }

  const job = jobResult.data[0];
  if (job.paid) return json({ success: true, message: 'Print job already paid', job });

  const reference = 'SIM-' + require('crypto').randomBytes(8).toString('hex').toUpperCase();

  const updateResult = await supabaseRequest('PATCH', '/print_jobs?id=eq.' + job.id, {
    status: 'ready_to_print',
    paid: true,
    payment_ref: reference,
    updated_at: new Date().toISOString()
  });

  if (updateResult.error) return err('Failed to update job status', 500);

  return json({
    success: true,
    message: 'Payment simulated - job marked as paid',
    job_id: jobId,
    reference
  });
}

async function handleCheckStatus(event) {
  let jobId;
  if (event.httpMethod === 'GET') {
    const url = new URL(event.rawUrl);
    jobId = url.searchParams.get('job_id') || '';
  } else {
    const body = JSON.parse(event.body || '{}');
    jobId = body.job_id || '';
  }

  if (!jobId) return err('Job ID required');

  const result = await supabaseRequest('GET', '/print_jobs', null, {
    'job_id': 'eq.' + jobId,
    'select': 'status,paid,payment_ref,total_price'
  });

  if (result.error || !result.data || result.data.length === 0) {
    return err('Print job not found', 404);
  }

  return json({ success: true, job: result.data[0] });
}
