import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { ThemeProvider } from './contexts/ThemeContext';
import { UserProvider } from './contexts/UserContext';
import { LanguageProvider } from './contexts/LanguageContext';
import Layout from './components/Layout';
import Login from './components/Login';
import WaveReview from './components/WaveReview';
import ProjectReviewPage from './components/ProjectReviewPage';
import ProtectedRoute from './components/ProtectedRoute';
import MigrationsList from './components/MigrationsList';
import MigrationDetails from './components/MigrationDetails';
import RunMigration from './components/RunMigration';
import Logs from './components/Logs';
import { Settings } from './components/Settings';
import Wave from './components/Wave';
import WaveDetails from './components/WaveDetails';
import WaveMapping from './components/WaveMapping';
import TestMigrationsList from './components/TestMigrationsList';
import TestRunMigration from './components/TestRunMigration';
import TestMigrationDetails from './components/TestMigrationDetails';
import UsersManagement from './components/UsersManagement';
import GoogleSheets from './components/GoogleSheets/GoogleSheets';
import './App.css';

function App() {
  return (
    <ThemeProvider>
      <UserProvider>
        <LanguageProvider>
        <BrowserRouter 
        basename="/"
        future={{
          v7_startTransition: true,
          v7_relativeSplatPath: true,
        }}
      >
        <Routes>
          {/* Публичные маршруты (без авторизации) */}
          <Route path="/login" element={<Login />} />
          <Route path="/review/:token" element={<WaveReview />} />
          <Route path="/review/:token/project/:brzProjectId" element={<ProjectReviewPage />} />
          
          {/* Защищенные маршруты (требуют авторизации) */}
          <Route path="/*" element={
            <Layout>
              <Routes>
                <Route path="/" element={<ProtectedRoute><MigrationsList /></ProtectedRoute>} />
                <Route path="/migrations/:id" element={<ProtectedRoute><MigrationDetails /></ProtectedRoute>} />
                <Route path="/run" element={<ProtectedRoute><RunMigration /></ProtectedRoute>} />
                <Route path="/logs" element={<ProtectedRoute><Logs /></ProtectedRoute>} />
                <Route path="/settings" element={<ProtectedRoute><Settings /></ProtectedRoute>} />
                <Route path="/wave" element={<ProtectedRoute><Wave /></ProtectedRoute>} />
                <Route path="/wave/:id" element={<ProtectedRoute><WaveDetails /></ProtectedRoute>} />
                <Route path="/wave/:id/mapping" element={<ProtectedRoute><WaveMapping /></ProtectedRoute>} />
                <Route path="/test" element={<ProtectedRoute><TestMigrationsList /></ProtectedRoute>} />
                <Route path="/test/run" element={<ProtectedRoute><TestRunMigration /></ProtectedRoute>} />
                <Route path="/test/:id" element={<ProtectedRoute><TestMigrationDetails /></ProtectedRoute>} />
                <Route path="/users" element={<ProtectedRoute><UsersManagement /></ProtectedRoute>} />
                <Route path="/google-sheets" element={<ProtectedRoute><GoogleSheets /></ProtectedRoute>} />
                <Route path="*" element={<Navigate to="/" replace />} />
              </Routes>
            </Layout>
          } />
        </Routes>
      </BrowserRouter>
      </LanguageProvider>
      </UserProvider>
    </ThemeProvider>
  );
}

export default App;
