<template>
  <div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">考前身份核验</h1>
      <router-link to="/exams" class="text-sm text-gray-500 hover:text-indigo-600">返回考试列表</router-link>
    </div>

    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <template v-else>
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-gray-700"><span class="font-medium">考试科目：</span>{{ paperTitle }}</p>
      </div>

      <!-- 隐私提示 -->
      <div class="bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-800 flex space-x-2">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
        </svg>
        <p>
          您上传的证件照片与人脸照片<strong>仅用于本次考试的身份核验</strong>，将在提交后保留
          <strong>{{ retentionDays }} 天</strong>并自动删除，到期后任何人（含管理员）均无法查看。
        </p>
      </div>

      <!-- 已通过 -->
      <div v-if="currentStatus === 'passed'" class="bg-white rounded-lg shadow p-8 text-center space-y-4">
        <div class="mx-auto w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
          <svg class="w-9 h-9 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 class="text-xl font-semibold text-gray-900">身份核验已通过</h2>
        <p class="text-gray-500 text-sm" v-if="verification?.similarity">比对相似度：{{ verification.similarity }}%</p>
        <button @click="enterExam" :disabled="entering" class="bg-indigo-600 text-white py-2 px-8 rounded-lg hover:bg-indigo-700 disabled:opacity-50">
          {{ entering ? '正在进入...' : '进入考试' }}
        </button>
      </div>

      <!-- 疑似：等待人工确认 -->
      <div v-else-if="currentStatus === 'suspected'" class="bg-white rounded-lg shadow p-8 text-center space-y-4">
        <div class="mx-auto w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center">
          <svg class="w-9 h-9 text-yellow-600 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <h2 class="text-xl font-semibold text-gray-900">等待监考老师人工确认</h2>
        <p class="text-gray-500 text-sm">
          系统比对结果为疑似（相似度 {{ verification?.similarity }}%），已提交监考老师复核。<br>
          页面每 5 秒自动刷新审核结果，请稍候。
        </p>
      </div>

      <!-- 核验表单（未提交 / 失败重试） -->
      <template v-else>
        <div v-if="currentStatus === 'failed'" class="bg-red-50 border border-red-100 rounded-lg p-4 text-sm text-red-700">
          上一次核验未通过{{ verification?.fail_reason ? `：${verification.fail_reason}` : '' }}。
          <span v-if="attemptsLeft > 0">剩余核验次数：{{ attemptsLeft }} 次，请调整后重新提交。</span>
          <span v-else>本场考试核验次数已用完，请联系监考老师处理。</span>
        </div>

        <template v-if="attemptsLeft > 0">
          <!-- 步骤指示 -->
          <div class="flex items-center justify-center space-x-4 text-sm">
            <div class="flex items-center space-x-2">
              <span class="w-7 h-7 rounded-full flex items-center justify-center font-medium" :class="step >= 1 ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-500'">1</span>
              <span :class="step >= 1 ? 'text-gray-900 font-medium' : 'text-gray-400'">证件信息</span>
            </div>
            <div class="w-10 h-px bg-gray-300"></div>
            <div class="flex items-center space-x-2">
              <span class="w-7 h-7 rounded-full flex items-center justify-center font-medium" :class="step >= 2 ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-500'">2</span>
              <span :class="step >= 2 ? 'text-gray-900 font-medium' : 'text-gray-400'">人脸采集</span>
            </div>
            <div class="w-10 h-px bg-gray-300"></div>
            <div class="flex items-center space-x-2">
              <span class="w-7 h-7 rounded-full flex items-center justify-center font-medium" :class="step >= 3 ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-500'">3</span>
              <span :class="step >= 3 ? 'text-gray-900 font-medium' : 'text-gray-400'">提交核验</span>
            </div>
          </div>

          <!-- 第一步：证件信息 -->
          <div v-show="step === 1" class="bg-white rounded-lg shadow p-6 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">证件姓名</label>
                <input v-model.trim="idName" type="text" placeholder="与证件一致的姓名" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500">
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">证件号码</label>
                <input v-model.trim="idNumber" type="text" placeholder="身份证号 / 护照号等" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500">
                <p class="text-xs text-gray-400 mt-1">证件号将脱敏存储，仅用于本次核验</p>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">证件照片（人像面）</label>
              <div class="flex items-start space-x-4">
                <label class="flex-1 border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/40 transition-colors">
                  <input type="file" accept="image/*" class="hidden" @change="onIdPhotoChange">
                  <svg class="w-8 h-8 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                  <p class="mt-2 text-sm text-gray-500">点击上传证件照片</p>
                  <p class="text-xs text-gray-400">支持 JPG / PNG / WebP，不超过 5MB</p>
                </label>
                <img v-if="idPhotoPreview" :src="idPhotoPreview" alt="证件照预览" class="w-40 h-28 object-cover rounded-lg border border-gray-200">
              </div>
            </div>
            <div class="flex justify-end">
              <button @click="goStep2" class="bg-indigo-600 text-white py-2 px-6 rounded-lg hover:bg-indigo-700">下一步</button>
            </div>
          </div>

          <!-- 第二步：人脸采集 -->
          <div v-show="step === 2" class="bg-white rounded-lg shadow p-6 space-y-5">
            <p class="text-sm text-gray-600">请正对摄像头，确保光线充足、面部无遮挡，然后点击“拍摄”。</p>
            <div class="flex flex-col md:flex-row items-start gap-4">
              <div class="flex-1 w-full">
                <div class="relative bg-gray-900 rounded-lg overflow-hidden aspect-video flex items-center justify-center">
                  <video ref="videoEl" autoplay playsinline muted class="w-full h-full object-cover"></video>
                  <div v-if="cameraError" class="absolute inset-0 flex items-center justify-center bg-gray-900/80 p-4">
                    <p class="text-sm text-gray-200 text-center">{{ cameraError }}</p>
                  </div>
                </div>
                <div class="mt-3 flex space-x-3">
                  <button v-if="!cameraError" @click="captureFace" class="bg-indigo-600 text-white py-2 px-5 rounded-lg hover:bg-indigo-700">拍摄人脸照片</button>
                  <label class="bg-white border border-gray-300 text-gray-700 py-2 px-5 rounded-lg cursor-pointer hover:bg-gray-50">
                    上传人脸照片
                    <input type="file" accept="image/*" capture="user" class="hidden" @change="onFacePhotoChange">
                  </label>
                </div>
              </div>
              <div v-if="facePreview" class="w-full md:w-48">
                <p class="text-sm text-gray-500 mb-1">采集预览</p>
                <img :src="facePreview" alt="人脸照片预览" class="w-full rounded-lg border border-gray-200">
                <button @click="retakeFace" class="mt-2 text-sm text-indigo-600 hover:underline">重新拍摄</button>
              </div>
            </div>
            <div class="flex justify-between">
              <button @click="step = 1" class="bg-gray-100 text-gray-700 py-2 px-6 rounded-lg hover:bg-gray-200">上一步</button>
              <button @click="step = 3" :disabled="!faceBlob" class="bg-indigo-600 text-white py-2 px-6 rounded-lg hover:bg-indigo-700 disabled:opacity-50">下一步</button>
            </div>
          </div>

          <!-- 第三步：确认提交 -->
          <div v-show="step === 3" class="bg-white rounded-lg shadow p-6 space-y-5">
            <h3 class="font-medium text-gray-900">请确认核验材料</h3>
            <div class="grid grid-cols-2 gap-4 text-sm">
              <div><span class="text-gray-500">证件姓名：</span>{{ idName }}</div>
              <div><span class="text-gray-500">证件号码：</span>{{ maskedIdNumberPreview }}</div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <p class="text-sm text-gray-500 mb-1">证件照片</p>
                <img :src="idPhotoPreview" alt="证件照" class="w-full h-40 object-cover rounded-lg border border-gray-200">
              </div>
              <div>
                <p class="text-sm text-gray-500 mb-1">人脸照片</p>
                <img :src="facePreview" alt="人脸照" class="w-full h-40 object-cover rounded-lg border border-gray-200">
              </div>
            </div>
            <label class="flex items-start space-x-2 text-sm text-gray-600">
              <input v-model="agreed" type="checkbox" class="mt-1 h-4 w-4 text-indigo-600 border-gray-300 rounded">
              <span>我确认所提交的材料真实有效，并知悉材料仅用于本次考试身份核验，将在 {{ retentionDays }} 天后自动删除。</span>
            </label>
            <div class="flex justify-between">
              <button @click="step = 2" class="bg-gray-100 text-gray-700 py-2 px-6 rounded-lg hover:bg-gray-200">上一步</button>
              <button @click="submitVerification" :disabled="!agreed || submitting" class="bg-indigo-600 text-white py-2 px-6 rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                {{ submitting ? '核验中...' : '提交核验' }}
              </button>
            </div>
          </div>
        </template>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../api'
import { useModal } from '../../composables/useModal'

const route = useRoute()
const router = useRouter()
const { alert } = useModal()

const paperId = route.params.id
const paperTitle = ref('')
const loading = ref(true)
const verification = ref(null)
const attemptsLeft = ref(0)
const retentionDays = ref(7)
const entering = ref(false)

const step = ref(1)
const idName = ref('')
const idNumber = ref('')
const idPhotoFile = ref(null)
const idPhotoPreview = ref('')
const faceBlob = ref(null)
const facePreview = ref('')
const agreed = ref(false)
const submitting = ref(false)

const videoEl = ref(null)
const stream = ref(null)
const cameraError = ref('')

let pollTimer = null

const currentStatus = computed(() => verification.value?.status || null)

const maskedIdNumberPreview = computed(() => {
  const n = idNumber.value
  if (!n) return '-'
  if (n.length <= 2) return '*'.repeat(n.length)
  if (n.length <= 6) return n[0] + '*'.repeat(n.length - 2) + n[n.length - 1]
  return n.slice(0, 3) + '*'.repeat(n.length - 5) + n.slice(-2)
})

const fetchStatus = async () => {
  const response = await api.get(`/identity/exams/${paperId}/verification`)
  paperTitle.value = response.data.exam_paper?.title || ''
  verification.value = response.data.verification
  attemptsLeft.value = response.data.attempts_left
  retentionDays.value = response.data.retention_days
  return response.data
}

onMounted(async () => {
  try {
    const data = await fetchStatus()
    if (data.verification?.status === 'suspected') {
      startPolling()
    }
  } catch (e) {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  stopCamera()
  stopPolling()
})

watch(step, async (val) => {
  if (val === 2) {
    await nextTick()
    startCamera()
  } else {
    stopCamera()
  }
})

const onIdPhotoChange = (e) => {
  const file = e.target.files?.[0]
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    alert('证件照片不能超过 5MB', '文件过大', 'error')
    return
  }
  idPhotoFile.value = file
  idPhotoPreview.value = URL.createObjectURL(file)
}

const onFacePhotoChange = (e) => {
  const file = e.target.files?.[0]
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    alert('人脸照片不能超过 5MB', '文件过大', 'error')
    return
  }
  faceBlob.value = file
  facePreview.value = URL.createObjectURL(file)
}

const goStep2 = () => {
  if (!idName.value) {
    alert('请填写证件姓名', '信息不完整', 'warning')
    return
  }
  if (!idNumber.value || idNumber.value.length < 6) {
    alert('请填写有效的证件号码', '信息不完整', 'warning')
    return
  }
  if (!idPhotoFile.value) {
    alert('请上传证件照片', '信息不完整', 'warning')
    return
  }
  step.value = 2
}

const startCamera = async () => {
  cameraError.value = ''
  try {
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error('unsupported')
    }
    stream.value = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' }
    })
    if (videoEl.value) {
      videoEl.value.srcObject = stream.value
    }
  } catch (e) {
    cameraError.value = '无法访问摄像头，请检查浏览器权限，或改用“上传人脸照片”'
  }
}

const stopCamera = () => {
  if (stream.value) {
    stream.value.getTracks().forEach(t => t.stop())
    stream.value = null
  }
}

const captureFace = () => {
  const video = videoEl.value
  if (!video || !video.videoWidth) return
  const canvas = document.createElement('canvas')
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
  canvas.toBlob((blob) => {
    if (blob) {
      faceBlob.value = new File([blob], 'face.jpg', { type: 'image/jpeg' })
      facePreview.value = URL.createObjectURL(blob)
    }
  }, 'image/jpeg', 0.92)
}

const retakeFace = () => {
  faceBlob.value = null
  facePreview.value = ''
}

const submitVerification = async () => {
  if (submitting.value) return
  submitting.value = true
  try {
    const formData = new FormData()
    formData.append('id_name', idName.value)
    formData.append('id_number', idNumber.value)
    formData.append('id_photo', idPhotoFile.value)
    formData.append('face_photo', faceBlob.value)

    const response = await api.post(`/identity/exams/${paperId}/verification`, formData)
    verification.value = response.data.verification
    attemptsLeft.value = response.data.attempts_left

    if (verification.value.status === 'suspected') {
      startPolling()
    }
  } catch (e) {
    const code = e.response?.data?.code
    if (code === 'UNDER_REVIEW' || code === 'ALREADY_PASSED' || code === 'NO_ATTEMPTS_LEFT') {
      await fetchStatus()
      if (verification.value?.status === 'suspected') startPolling()
    }
  } finally {
    submitting.value = false
  }
}

const startPolling = () => {
  stopPolling()
  pollTimer = setInterval(async () => {
    try {
      const data = await fetchStatus()
      if (data.verification?.status !== 'suspected') {
        stopPolling()
      }
    } catch (e) {
      stopPolling()
    }
  }, 5000)
}

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

const enterExam = async () => {
  entering.value = true
  try {
    await api.post(`/exams/${paperId}/start`, {}, { skipGlobalErrorHandler: true })
    router.push(`/exams/${paperId}`)
  } catch (e) {
    alert(e.response?.data?.message || '进入考试失败', '进入考试', 'error')
  } finally {
    entering.value = false
  }
}
</script>
