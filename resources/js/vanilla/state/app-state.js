export function createAppState(initialState = {}) {
    let currentState = { ...initialState };
    const listeners = new Set();

    return {
        getState() {
            return { ...currentState };
        },
        setState(patch) {
            currentState = { ...currentState, ...patch };
            for (const listener of listeners) {
                listener({ ...currentState });
            }
        },
        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
    };
}
