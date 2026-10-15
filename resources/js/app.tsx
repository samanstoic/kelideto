import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import { AppShell } from './components/layout/AppShell';
import { LandingPage } from './pages/LandingPage';
import { AuthPage } from './pages/AuthPage';
import { ServiceSelectionPage } from './pages/ServiceSelectionPage';
import { PanelsPage } from './pages/PanelsPage';

export const App: React.FC = () => {
  const [activeTab, setActiveTab] = useState<string>('landing');
  const [isOffline, setIsOffline] = useState<boolean>(!navigator.onLine);

  useEffect(() => {
    const handleOnline = () => setIsOffline(false);
    const handleOffline = () => setIsOffline(true);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  const handleLoginSuccess = (role: string) => {
    if (role === 'technician') {
      setActiveTab('panels');
    } else {
      setActiveTab('booking');
    }
  };

  return (
    <AppShell activeTab={activeTab} onTabChange={setActiveTab} isOffline={isOffline}>
      {activeTab === 'landing' && (
        <LandingPage onNavigateToBooking={() => setActiveTab('booking')} />
      )}
      {activeTab === 'booking' && <ServiceSelectionPage />}
      {activeTab === 'auth' && <AuthPage onLoginSuccess={handleLoginSuccess} />}
      {activeTab === 'panels' && <PanelsPage />}
    </AppShell>
  );
};

const container = document.getElementById('app');
if (container) {
  const root = createRoot(container);
  root.render(<App />);
}
