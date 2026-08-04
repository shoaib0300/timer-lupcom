
import { withCsrf } from './csrf.js';

export async function createManualEntry(formData) {
    const body = withCsrf(new URLSearchParams(formData));
    const response = await fetch('/api/time-entries/manual', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });

    const data = await response.json();

    if (!response.ok) {
        alert(data.error || 'Could not log time.');
        return null;
    }

    return data;
}

export async function fetchProjectTasks(projectId) {
    const response = await fetch(`/api/projects/${projectId}/tasks`);

    if (!response.ok) {
        return [];
    }

    const data = await response.json();
    return data.tasks || [];
}

export async function fetchTimerStatus() {
    const response = await fetch('/api/timer/status');
    return response.json();
}

export async function postTimerAction(url, entryId, extraFields = {}) {
    const formData = new FormData();
    formData.set('entry_id', String(entryId));

    Object.entries(extraFields).forEach(([key, value]) => {
        if (value === undefined || value === null) {
            return;
        }
        formData.set(key, String(value));
    });

    if (typeof window.__CSRF__ === 'string' && window.__CSRF__ !== '') {
        formData.set('_token', window.__CSRF__);
    }

    const response = await fetch(url, {
        method: 'POST',
        body: formData,
    });
    const data = await response.json();

    if (!response.ok) {
        alert(data.error || 'Timer action failed.');
        return null;
    }

    return data;
}

export async function startTimer(projectId, taskName) {
    const body = withCsrf(new URLSearchParams({
        project_id: projectId,
        task_name: taskName || 'no-work',
    }));

    const response = await fetch('/api/timer/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });

    const data = await response.json();
    if (!response.ok) {
        alert(data.error || 'Could not start timer.');
        return null;
    }

    return data;
}

export async function startTimerByTaskId(taskId) {
    const body = withCsrf(new URLSearchParams({ task_id: taskId }));

    const response = await fetch('/api/timer/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });

    const data = await response.json();
    if (!response.ok) {
        alert(data.error || 'Could not start timer.');
        return null;
    }

    return data;
}

export async function searchTasks(query) {
    const params = new URLSearchParams({ q: query });
    const response = await fetch(`/api/tasks/search?${params}`);

    if (!response.ok) {
        return [];
    }

    const data = await response.json();
    return data.tasks || [];
}

export async function fetchFrequentTasks() {
    const response = await fetch('/api/tasks/frequent');

    if (!response.ok) {
        return [];
    }

    const data = await response.json();
    return data.tasks || [];
}

export async function fetchPlanioActivities() {
    const response = await fetch('/api/planio/activities');
    if (!response.ok) {
        return [];
    }

    const data = await response.json();
    return data.activities || [];
}

export async function deleteTimeEntry(entryId) {
    const formData = new FormData();
    if (typeof window.__CSRF__ === 'string' && window.__CSRF__ !== '') {
        formData.set('_token', window.__CSRF__);
    }

    const response = await fetch(`/api/time-entries/${entryId}/delete`, {
        method: 'POST',
        body: formData,
    });
    const data = await response.json();

    if (!response.ok) {
        alert(data.error || 'Could not delete time entry.');
        return null;
    }

    return data;
}
