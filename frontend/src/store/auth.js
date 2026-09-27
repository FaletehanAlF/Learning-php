import { defineStore } from 'pinia'
import { authService } from '../services/services'

// Store auth: token di localStorage, user di memori + localStorage
export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('token') || '',
    user: JSON.parse(localStorage.getItem('user') || 'null'),
  }),
  getters: {
    isLoggedIn: (s) => !!s.token,
    role: (s) => s.user?.role || '',
    canInput: (s) => ['admin', 'kasir'].includes(s.user?.role),
    canDelete: (s) => ['admin', 'supervisor'].includes(s.user?.role),
    isAdmin: (s) => s.user?.role === 'admin',
    canAudit: (s) => ['admin', 'supervisor'].includes(s.user?.role),
  },
  actions: {
    async login(login, password) {
      const { data } = await authService.login(login, password)
      this.token = data.token
      this.user = data.user
      localStorage.setItem('token', data.token)
      localStorage.setItem('user', JSON.stringify(data.user))
      return data.user
    },
    async fetchMe() {
      try {
        const { data } = await authService.me()
        this.user = data.user
        localStorage.setItem('user', JSON.stringify(data.user))
      } catch {
        this.logout()
      }
    },
    async logout() {
      try {
        await authService.logout()
      } catch {
        /* token mungkin sudah invalid */
      }
      this.token = ''
      this.user = null
      localStorage.removeItem('token')
      localStorage.removeItem('user')
    },
  },
})
