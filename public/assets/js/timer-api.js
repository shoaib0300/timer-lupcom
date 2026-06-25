export async function createManualEntry(formData) {
    const body = new URLSearchParams(formData);
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

export async function postTimerAction(url, entryId) {
    const body = new URLSearchParams({ entry_id: entryId });
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });
    const data = await response.json();

    if (!response.ok) {
        alert(data.error || 'Timer action failed.');
        return null;
    }

    return data;
}

export async function startTimer(projectId, taskName) {
    const body = new URLSearchParams({
        project_id: projectId,
        task_name: taskName || 'no-work',
    });

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
