const { json, err, supabaseRequest, supabaseStorageDelete } = require('./_shared');

exports.handler = async (event) => {
  if (event.httpMethod === 'OPTIONS') return json({});

  if (event.httpMethod !== 'GET' && event.httpMethod !== 'POST') return err('Method not allowed', 405);

  const url = new URL(event.rawUrl);
  const action = url.searchParams.get('action');

  if (event.httpMethod === 'POST' && action === 'complete-job') {
    return await handleCompleteJob(event);
  }
  if (event.httpMethod === 'POST' && action === 'delete-job') {
    return await handleDeleteJob(event);
  }
  if (event.httpMethod === 'POST' && action === 'submit-report') {
    return await handleSubmitReport(event);
  }

  switch (action) {
    case 'get-jobs': {
      const userId = url.searchParams.get('user_id') || '';
      if (!userId) return err('User ID required');
      const result = await supabaseRequest('GET', '/print_jobs', null, {
        'user_id': 'eq.' + userId,
        'select': '*',
        'status': 'not.in.(completed,refund_issued)',
        'order': 'created_at.desc'
      });
      return json({ success: true, data: result.error ? [] : result.data });
    }

    case 'get-all-jobs': {
      const userId = url.searchParams.get('user_id') || '';
      if (!userId) return err('User ID required');
      const result = await supabaseRequest('GET', '/print_jobs', null, {
        'user_id': 'eq.' + userId,
        'select': '*',
        'order': 'created_at.desc'
      });
      return json({ success: true, data: result.error ? [] : result.data });
    }

    case 'get-face-users': {
      const result = await supabaseRequest('GET', '/users', null, {
        'select': 'id,name,face_descriptor',
        'face_descriptor': 'not.is.null',
        'role': 'eq.customer'
      });
      return json({ success: true, data: result.error ? [] : result.data });
    }

    case 'get-user': {
      const userId = url.searchParams.get('id') || '';
      if (!userId) return err('User ID required');
      const result = await supabaseRequest('GET', '/users', null, {
        'id': 'eq.' + userId,
        'select': '*'
      });
      return json({ success: true, data: result.error || !result.data || !result.data.length ? null : result.data[0] });
    }

    case 'get-users-with-face': {
      const result = await supabaseRequest('GET', '/users', null, {
        'select': 'id,name,face_descriptor',
        'face_descriptor': 'not.is.null'
      });
      return json({ success: true, data: result.error ? [] : result.data });
    }

    default:
      return err('Invalid action');
  }
};

async function handleCompleteJob(event) {
  const body = JSON.parse(event.body || '{}');
  const jobId = body.job_id || '';
  if (!jobId) return err('job_id required');

  const jobResult = await supabaseRequest('GET', '/print_jobs', null, {
    'job_id': 'eq.' + jobId,
    'select': 'id,file_path'
  });
  const job = jobResult.data && jobResult.data[0];
  if (!job) return err('Job not found', 404);

  await supabaseRequest('PATCH', '/print_jobs?job_id=eq.' + jobId, {
    'status': 'completed',
    'print_pin': null
  });

  const filePath = job.file_path || '';
  if (filePath && filePath.includes('/storage/v1/object/')) {
    try {
      const storageUrl = filePath.split('/storage/v1/object/')[1];
      const parts = storageUrl.split('/');
      let bucket, path;
      if (parts[0] === 'public') {
        bucket = parts[1];
        path = parts.slice(2).join('/');
      } else {
        bucket = parts[0];
        path = parts.slice(1).join('/');
      }
      await supabaseStorageDelete(bucket, path);
    } catch(e) {}
  }

  return json({ success: true, message: 'Job completed and cleaned up' });
}

async function handleDeleteJob(event) {
  const body = JSON.parse(event.body || '{}');
  const jobId = body.job_id || '';
  if (!jobId) return err('job_id required');

  const jobResult = await supabaseRequest('GET', '/print_jobs', null, {
    'job_id': 'eq.' + jobId,
    'select': 'id,file_path'
  });
  const job = jobResult.data && jobResult.data[0];
  if (!job) return err('Job not found', 404);

  await supabaseRequest('PATCH', '/print_jobs?job_id=eq.' + jobId, {
    'status': 'completed',
    'print_pin': null
  });

  const filePath = job.file_path || '';
  if (filePath && filePath.includes('/storage/v1/object/')) {
    try {
      const storageUrl = filePath.split('/storage/v1/object/')[1];
      const parts = storageUrl.split('/');
      let bucket, path;
      if (parts[0] === 'public') {
        bucket = parts[1];
        path = parts.slice(2).join('/');
      } else {
        bucket = parts[0];
        path = parts.slice(1).join('/');
      }
      await supabaseStorageDelete(bucket, path);
    } catch(e) {}
  }

  return json({ success: true, message: 'Job deleted' });
}

async function handleSubmitReport(event) {
  const body = JSON.parse(event.body || '{}');
  const type = (body.type || '').trim();
  const description = (body.description || '').trim();
  const jobId = (body.job_id || '').trim();
  const userEmail = (body.user_email || '').trim();

  if (!description) return err('Description required');

  const typeLabels = {
    print_error: 'Print Error', payment_issue: 'Payment Issue',
    machine_problem: 'Machine Problem', quality_issue: 'Quality Issue', other: 'Other'
  };
  const typeLabel = typeLabels[type] || (type ? type.charAt(0).toUpperCase() + type.slice(1) : 'General');

  const parts = ['[Report] ' + typeLabel, description];
  if (jobId) parts.push('Job: ' + jobId);
  if (userEmail) parts.push('From: ' + userEmail);
  const message = parts.join(' | ');

  const alertTypes = {
    print_error: 'print_complete', payment_issue: 'payment_received',
    machine_problem: 'paper_jam', quality_issue: 'print_complete', other: 'paper_low'
  };

  let result = await supabaseRequest('POST', '/alerts', {
    type: 'user_report', message, severity: 'warning', is_read: false
  });

  if (result.error) {
    result = await supabaseRequest('POST', '/alerts', {
      type: alertTypes[type] || 'print_complete', message, severity: 'warning', is_read: false
    });
  }

  if (result.error) return err('Failed to submit report', 500);
  return json({ success: true, message: 'Report submitted' });
}
