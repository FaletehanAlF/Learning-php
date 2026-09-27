import api from './api'

export const authService = {
  login(login, password, remember = false) {
    return api.post('/auth/login', { login, password, remember })
  },
  logout() {
    return api.post('/auth/logout')
  },
  me() {
    return api.get('/auth/me')
  },
  forgot(email) {
    return api.post('/auth/forgot', { email })
  },
  reset(token, password, password_confirmation) {
    return api.post('/auth/reset', { token, password, password_confirmation })
  },
}

export const transactionService = {
  list(params = {}) {
    return api.get('/transactions', { params })
  },
  get(id) {
    return api.get(`/transactions/${id}`)
  },
  // data = FormData (mendukung upload bukti) atau object JSON
  create(data) {
    const isForm = typeof FormData !== 'undefined' && data instanceof FormData
    return api.post('/transactions', data, isForm ? { headers: { 'Content-Type': 'multipart/form-data' } } : {})
  },
  update(id, data) {
    const isForm = typeof FormData !== 'undefined' && data instanceof FormData
    if (isForm) {
      data.append('_method', 'PUT')
      return api.post(`/transactions/${id}`, data, { headers: { 'Content-Type': 'multipart/form-data' } })
    }
    return api.put(`/transactions/${id}`, data)
  },
  remove(id) {
    return api.delete(`/transactions/${id}`)
  },
}

export const categoryService = {
  list(type = '') {
    return api.get('/categories', { params: type ? { type } : {} })
  },
  create(data) {
    return api.post('/categories', data)
  },
  update(id, data) {
    return api.put(`/categories/${id}`, data)
  },
  remove(id) {
    return api.delete(`/categories/${id}`)
  },
}

export const reportService = {
  daily(date, exportCsv = false) {
    return api.get('/reports/daily', {
      params: { date, ...(exportCsv ? { export: 'csv' } : {}) },
      ...(exportCsv ? { responseType: 'blob' } : {}),
    })
  },
  monthly(month, exportCsv = false) {
    return api.get('/reports/monthly', {
      params: { month, ...(exportCsv ? { export: 'csv' } : {}) },
      ...(exportCsv ? { responseType: 'blob' } : {}),
    })
  },
  yearly(year, exportCsv = false) {
    return api.get('/reports/yearly', {
      params: { year, ...(exportCsv ? { export: 'csv' } : {}) },
      ...(exportCsv ? { responseType: 'blob' } : {}),
    })
  },
}

export const dashboardService = {
  get() {
    return api.get('/dashboard')
  },
}

export const adminService = {
  logs() {
    return api.get('/logs')
  },
  backup() {
    return api.post('/backup')
  },
  users() {
    return api.get('/users')
  },
  createUser(data) {
    return api.post('/users', data)
  },
}

export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(new Blob([blob]))
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}
