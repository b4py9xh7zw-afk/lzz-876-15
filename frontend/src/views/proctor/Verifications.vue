<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">监考核验</h1>
      <p class="text-sm text-gray-500">查看核验照片将记录审计日志；材料到期自动清理后不可查看</p>
    </div>

    <!-- 筛选 -->
    <div class="bg-white rounded-lg shadow p-4 flex flex-wrap items-center gap-3">
      <div class="flex space-x-1 bg-gray-100 rounded-lg p-1">
        <button
          v-for="tab in statusTabs"
          :key="tab.value"
          @click="changeStatus(tab.value)"
          class="px-4 py-1.5 text-sm rounded-md transition-colors"
          :class="filters.status === tab.value ? 'bg-white shadow text-indigo-600 font-medium' : 'text-gray-600 hover:text-gray-900'"
        >
          {{ tab.label }}
        </button>
      </div>
      <select v-model="filters.exam_paper_id" @change="fetchList(1)" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500">
        <option value="">全部试卷</option>
        <option v-for="p in papers" :key="p.id" :value="p.id">{{ p.title }}</option>
      </select>
    </div>

    <!-- 列表 -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
      <div v-if="loading" class="text-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      </div>
      <div v-else-if="rows.length === 0" class="text-center py-12 text-gray-500">暂无核验记录</div>
      <table v-else class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">考生</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">试卷</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">证件姓名</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">证件号</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">相似度</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">材料</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">提交时间</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50">
            <td class="px-4 py-3 text-sm text-gray-900">{{ row.user?.real_name || row.user?.username }}</td>
            <td class="px-4 py-3 text-sm text-gray-600">{{ row.exam_paper?.title }}</td>
            <td class="px-4 py-3 text-sm text-gray-600">{{ row.id_name }}</td>
            <td class="px-4 py-3 text-sm text-gray-500 font-mono">{{ row.id_number_masked }}</td>
            <td class="px-4 py-3 text-sm text-gray-600">{{ row.similarity }}%</td>
            <td class="px-4 py-3">
              <span class="px-2 py-1 text-xs rounded-full" :class="statusBadge(row.status)">{{ statusLabel(row.status) }}</span>
            </td>
            <td class="px-4 py-3">
              <span v-if="row.materials_available" class="text-xs text-green-600">保留中</span>
              <span v-else class="text-xs text-gray-400">已清理</span>
            </td>
            <td class="px-4 py-3 text-sm text-gray-500">{{ formatTime(row.created_at) }}</td>
            <td class="px-4 py-3">
              <button @click="openDetail(row)" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                {{ row.status === 'suspected' ? '审核' : '查看' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- 分页 -->
      <div v-if="pagination.last_page > 1" class="px-4 py-3 border-t border-gray-200 flex justify-between items-center text-sm">
        <span class="text-gray-500">共 {{ pagination.total }} 条</span>
        <div class="space-x-2">
          <button @click="fetchList(pagination.current_page - 1)" :disabled="pagination.current_page <= 1" class="px-3 py-1 border rounded disabled:opacity-40">上一页</button>
          <button @click="fetchList(pagination.current_page + 1)" :disabled="pagination.current_page >= pagination.last_page" class="px-3 py-1 border rounded disabled:opacity-40">下一页</button>
        </div>
      </div>
    </div>

    <!-- 详情/审核弹窗 -->
    <Teleport to="body">
      <div v-if="detail" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-gray-600/75" @click="closeDetail"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto z-[100]">
          <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-lg font-bold text-gray-900">核验详情 #{{ detail.id }}</h3>
            <button @click="closeDetail" class="text-gray-400 hover:text-gray-600">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="p-6 space-y-5">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
              <div><span class="text-gray-500">考生：</span>{{ detail.user?.real_name || detail.user?.username }}</div>
              <div><span class="text-gray-500">试卷：</span>{{ detail.exam_paper?.title }}</div>
              <div><span class="text-gray-500">证件姓名：</span>{{ detail.id_name }}</div>
              <div><span class="text-gray-500">证件号：</span><span class="font-mono">{{ detail.id_number_masked }}</span></div>
              <div><span class="text-gray-500">比对相似度：</span>{{ detail.similarity }}%</div>
              <div>
                <span class="text-gray-500">状态：</span>
                <span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadge(detail.status)">{{ statusLabel(detail.status) }}</span>
              </div>
              <div><span class="text-gray-500">提交时间：</span>{{ formatTime(detail.created_at) }}</div>
              <div><span class="text-gray-500">保留截止：</span>{{ formatTime(detail.expires_at) }}</div>
              <div v-if="detail.reviewer"><span class="text-gray-500">审核人：</span>{{ detail.reviewer?.username }}（{{ formatTime(detail.reviewed_at) }}）</div>
              <div v-if="detail.review_note" class="col-span-2"><span class="text-gray-500">审核备注：</span>{{ detail.review_note }}</div>
              <div v-if="detail.fail_reason" class="col-span-2"><span class="text-gray-500">未通过原因：</span>{{ detail.fail_reason }}</div>
            </div>

            <!-- 照片对比 -->
            <div v-if="detail.materials_available" class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <p class="text-sm text-gray-500 mb-1">证件照片</p>
                <div class="border border-gray-200 rounded-lg overflow-hidden bg-gray-50 min-h-[10rem] flex items-center justify-center">
                  <img v-if="photos.id" :src="photos.id" alt="证件照片" class="w-full object-contain max-h-72">
                  <span v-else class="text-gray-400 text-sm py-10">加载中...</span>
                </div>
              </div>
              <div>
                <p class="text-sm text-gray-500 mb-1">现场人脸照片</p>
                <div class="border border-gray-200 rounded-lg overflow-hidden bg-gray-50 min-h-[10rem] flex items-center justify-center">
                  <img v-if="photos.face" :src="photos.face" alt="人脸照片" class="w-full object-contain max-h-72">
                  <span v-else class="text-gray-400 text-sm py-10">加载中...</span>
                </div>
              </div>
            </div>
            <div v-else class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-500 text-center">
              核验材料已过保留期并自动清理，无法查看照片（{{ formatTime(detail.purged_at) }} 清理）
            </div>

            <!-- 人工审核 -->
            <div v-if="detail.status === 'suspected'" class="border-t border-gray-100 pt-4 space-y-3">
              <p class="text-sm font-medium text-gray-900">人工确认</p>
              <textarea v-model="reviewNote" rows="2" placeholder="审核备注（驳回时必填原因）" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
              <div class="flex justify-end space-x-3">
                <button @click="submitReview('reject')" :disabled="reviewing" class="bg-red-600 text-white py-2 px-5 rounded-lg hover:bg-red-700 disabled:opacity-50">驳回</button>
                <button @click="submitReview('approve')" :disabled="reviewing" class="bg-green-600 text-white py-2 px-5 rounded-lg hover:bg-green-700 disabled:opacity-50">确认通过</button>
              </div>
            </div>

            <!-- 审计日志 -->
            <div class="border-t border-gray-100 pt-4">
              <button @click="toggleAudits" class="text-sm text-indigo-600 hover:underline">
                {{ showAudits ? '收起审计记录' : '查看审计记录' }}
              </button>
              <div v-if="showAudits" class="mt-3">
                <div v-if="audits.length === 0" class="text-sm text-gray-400">暂无审计记录</div>
                <ul v-else class="text-sm text-gray-600 space-y-1 max-h-40 overflow-y-auto">
                  <li v-for="a in audits" :key="a.id" class="flex justify-between">
                    <span>{{ auditLabel(a.action) }}<span class="text-gray-400">（{{ a.actor?.username || '系统' }}）</span><span v-if="a.detail" class="text-gray-400"> - {{ a.detail }}</span></span>
                    <span class="text-gray-400">{{ formatTime(a.created_at) }}</span>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import { useModal } from '../../composables/useModal'

const { alert } = useModal()

const statusTabs = [
  { label: '待审核', value: 'suspected' },
  { label: '已通过', value: 'passed' },
  { label: '未通过', value: 'failed' },
  { label: '全部', value: '' }
]

const rows = ref([])
const papers = ref([])
const loading = ref(true)
const filters = ref({ status: 'suspected', exam_paper_id: '' })
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })

const detail = ref(null)
const photos = ref({ id: '', face: '' })
const reviewNote = ref('')
const reviewing = ref(false)
const showAudits = ref(false)
const audits = ref([])

onMounted(async () => {
  fetchPapers()
  fetchList(1)
})

const fetchPapers = async () => {
  try {
    const res = await api.get('/exam-papers', { params: { per_page: 100 } })
    papers.value = res.data.exam_papers?.data || []
  } catch (e) { /* 拦截器已提示 */ }
}

const fetchList = async (page = 1) => {
  loading.value = true
  try {
    const params = { page, per_page: 15 }
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.exam_paper_id) params.exam_paper_id = filters.value.exam_paper_id
    const res = await api.get('/identity/verifications', { params })
    rows.value = res.data.verifications.data
    pagination.value = {
      current_page: res.data.verifications.current_page,
      last_page: res.data.verifications.last_page,
      total: res.data.verifications.total
    }
  } catch (e) { /* 拦截器已提示 */ } finally {
    loading.value = false
  }
}

const changeStatus = (val) => {
  filters.value.status = val
  fetchList(1)
}

const openDetail = async (row) => {
  reviewNote.value = ''
  showAudits.value = false
  audits.value = []
  photos.value = { id: '', face: '' }
  try {
    const res = await api.get(`/identity/verifications/${row.id}`)
    detail.value = res.data.verification
    if (detail.value.materials_available) {
      loadPhoto(row.id, 'id')
      loadPhoto(row.id, 'face')
    }
  } catch (e) { /* 拦截器已提示 */ }
}

const loadPhoto = async (id, type) => {
  try {
    const res = await api.get(`/identity/verifications/${id}/photo/${type}`, { responseType: 'blob' })
    photos.value[type] = URL.createObjectURL(res.data)
  } catch (e) { /* 已清理等情况由拦截器提示 */ }
}

const closeDetail = () => {
  if (photos.value.id) URL.revokeObjectURL(photos.value.id)
  if (photos.value.face) URL.revokeObjectURL(photos.value.face)
  detail.value = null
}

const submitReview = async (action) => {
  if (action === 'reject' && !reviewNote.value.trim()) {
    alert('驳回时请填写原因', '审核', 'warning')
    return
  }
  reviewing.value = true
  try {
    await api.post(`/identity/verifications/${detail.value.id}/review`, {
      action,
      note: reviewNote.value.trim() || null
    })
    closeDetail()
    fetchList(pagination.value.current_page)
  } catch (e) { /* 拦截器已提示 */ } finally {
    reviewing.value = false
  }
}

const toggleAudits = async () => {
  showAudits.value = !showAudits.value
  if (showAudits.value && audits.value.length === 0 && detail.value) {
    try {
      const res = await api.get(`/identity/verifications/${detail.value.id}/audits`)
      audits.value = res.data.audits
    } catch (e) { /* 拦截器已提示 */ }
  }
}

const statusLabel = (s) => ({ passed: '通过', suspected: '疑似', failed: '失败' }[s] || s)
const statusBadge = (s) => ({
  passed: 'bg-green-100 text-green-700',
  suspected: 'bg-yellow-100 text-yellow-700',
  failed: 'bg-red-100 text-red-700'
}[s] || 'bg-gray-100 text-gray-600')

const auditLabel = (a) => ({
  submit: '提交核验',
  view_id_photo: '查看证件照',
  view_face_photo: '查看人脸照',
  review_approve: '人工确认通过',
  review_reject: '人工驳回',
  purge: '到期自动清理'
}[a] || a)

const formatTime = (t) => (t ? new Date(t).toLocaleString('zh-CN', { hour12: false }) : '-')
</script>
