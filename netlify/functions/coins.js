const { json, err, supabaseRequest, validateSession } = require('./_shared');

exports.handler = async (event) => {
  if (event.httpMethod === 'OPTIONS') return json({});

  const session = await validateSession(event);
  if (!session) return err('Unauthorized', 401);

  const userId = session.user_id;
  const url = new URL(event.rawUrl);
  const action = url.searchParams.get('action');

  if (event.httpMethod === 'GET') {
    switch (action) {
      case 'balance': return getBalance(userId);
      case 'history': return getHistory(userId);
      case 'discount': return getDiscount(userId);
      default: return err('Invalid action');
    }
  } else if (event.httpMethod === 'POST') {
    switch (action) {
      case 'redeem': return redeemCoins(userId, event);
      default: return err('Invalid action');
    }
  }

  return err('Method not allowed', 405);
};

async function getBalance(userId) {
  const result = await supabaseRequest('GET', '/users', null, {
    'id': 'eq.' + userId,
    'select': 'coins'
  });

  if (result.error || !result.data || result.data.length === 0) {
    return err('Failed to get balance', 500);
  }

  const coins = result.data[0].coins;
  const discountValue = Math.round(coins * 0.01 * 100) / 100;

  return json({
    success: true,
    coins,
    discount_rm: discountValue,
    can_redeem: coins >= 10
  });
}

async function getHistory(userId) {
  const result = await supabaseRequest('GET', '/coin_transactions', null, {
    'user_id': 'eq.' + userId,
    'select': '*',
    'order': 'created_at.desc',
    'limit': '50'
  });

  return json({
    success: true,
    transactions: result.error ? [] : result.data
  });
}

async function getDiscount(userId) {
  const result = await supabaseRequest('GET', '/users', null, {
    'id': 'eq.' + userId,
    'select': 'coins'
  });

  if (result.error || !result.data || result.data.length === 0) {
    return json({ success: true, can_redeem: false, coins: 0, max_discount: 0 });
  }

  const coins = result.data[0].coins;
  return json({
    success: true,
    can_redeem: coins >= 10,
    coins,
    max_discount: Math.floor(coins / 10) * 0.1
  });
}

async function redeemCoins(userId, event) {
  const body = JSON.parse(event.body || '{}');
  const jobId = body.job_id || '';
  const redeemAmount = Math.round((parseFloat(body.amount) || 0) * 100) / 100;
  const coinsNeeded = Math.round(redeemAmount * 100);

  if (!jobId || redeemAmount <= 0) return err('Job ID and redeem amount required');

  if (coinsNeeded < 10 || coinsNeeded % 10 !== 0) {
    return err('Redeem amount must be in RM 0.10 steps (minimum 10 coins)');
  }

  const userResult = await supabaseRequest('GET', '/users', null, {
    'id': 'eq.' + userId,
    'select': 'coins'
  });

  if (userResult.error || !userResult.data || userResult.data.length === 0) {
    return err('Failed to get user data', 500);
  }

  const currentCoins = userResult.data[0].coins;

  if (currentCoins < coinsNeeded) return err('Insufficient coins', 400);

  const jobResult = await supabaseRequest('GET', '/print_jobs', null, {
    'job_id': 'eq.' + jobId,
    'select': '*'
  });

  if (jobResult.error || !jobResult.data || jobResult.data.length === 0) {
    return err('Print job not found', 404);
  }

  const job = jobResult.data[0];
  const newPrice = Math.max(0, job.total_price - redeemAmount);
  const newCoins = currentCoins - coinsNeeded;

  await supabaseRequest('PATCH', '/print_jobs?id=eq.' + job.id, {
    total_price: newPrice,
    updated_at: new Date().toISOString()
  });

  await supabaseRequest('PATCH', '/users?id=eq.' + userId, { coins: newCoins });

  await supabaseRequest('POST', '/coin_transactions', {
    user_id: userId,
    print_job_id: job.id,
    type: 'spent',
    amount: coinsNeeded,
    description: 'Redeemed RM ' + redeemAmount.toFixed(2) + ' discount for job ' + jobId,
    balance_after: newCoins
  });

  return json({
    success: true,
    message: 'Discount applied successfully',
    new_price: newPrice,
    coins_remaining: newCoins,
    coins_spent: coinsNeeded
  });
}
