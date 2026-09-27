import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../store/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('../pages/Login.vue'), meta: { guest: true } },
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', name: 'dashboard', component: () => import('../pages/Dashboard.vue'), meta: { auth: true } },
  { path: '/transactions', name: 'transactions', component: () => import('../pages/Transactions.vue'), meta: { auth: true } },
  { path: '/reports', name: 'reports', component: () => import('../pages/Reports.vue'), meta: { auth: true } },
  { path: '/categories', name: 'categories', component: () => import('../pages/Categories.vue'), meta: { auth: true } },
  { path: '/logs', name: 'logs', component: () => import('../pages/Logs.vue'), meta: { auth: true, roles: ['admin', 'supervisor'] } },
  { path: '/users', name: 'users', component: () => import('../pages/Users.vue'), meta: { auth: true, roles: ['admin'] } },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.auth && !auth.isLoggedIn) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.guest && auth.isLoggedIn) {
    return { name: 'dashboard' }
  }
  if (to.meta.roles && !to.meta.roles.includes(auth.role)) {
    return { name: 'dashboard' }
  }
  return true
})

export default router
