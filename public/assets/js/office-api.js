export async function fetchOfficeStatus() {
    const response = await fetch('/api/office/status');
    return response.json();
}

export async function postOfficeAction(url, sessionId) {
    const body = new URLSearchParams({ session_id: sessionId });
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });
    const data = await response.json();

    if (!response.ok) {
        alert(data.error || 'Office action failed.');
        return null;
    }

    return data;
}

export async function startOffice() {
    const response = await fetch('/api/office/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    });
    const data = await response.json();

    if (!response.ok) {
        alert(data.error || 'Could not start office session.');
        return null;
    }

    return data;
}
