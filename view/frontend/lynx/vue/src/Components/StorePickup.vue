<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'

// The in-store pickup renderer of the Lynx checkout's shipping method step

/** A store as the REST endpoint lists it (and as Model\PickupLocations formats the selected one). */
type Location = {
    pickup_location_code: string
    name?: string
    street?: string
    city?: string
    region?: string
    postcode?: string
    country_id?: string
    phone?: string
    description?: string
}
type SearchResult = { items?: Location[]; total_count?: number }

const props = withDefaults(
    defineProps<{
        config?: {
            search_url?: string
            website_code?: string
            skus?: string[]
            page_size?: number
            // The store already on the quote, so a reload keeps the pick.
            selected?: Location | null
            // Every string this shows, translated by Model\PickupLocations.
            labels?: Record<string, string>
        }
    }>(),
    {
        config: () => ({}),
    }
)

const checkout = useCheckoutStore()
const labels = computed(() => props.config.labels ?? {})

// The outlined button and field recipes the checkout uses.
const SECONDARY_BUTTON = 'l-btn l-btn-outline l-btn-secondary px-5 uppercase whitespace-nowrap'

// ── The pick ─────────────────────────────────────────────────────────────────

const selected = ref<Location | null>(props.config.selected ?? null)
const error = ref('')
/** The list is open: only once the visitor asks for it, as Luma's Select Store button does. */
const open = ref(false)

function pick(location: Location) {
    selected.value = location
    error.value = ''
    open.value = false
}

// ── The list ─────────────────────────────────────────────────────────────────

const query = ref('')
const items = ref<Location[]>([])
const total = ref(0)
const page = ref(0)
const loading = ref(false)
/** True once a request has answered: the empty message is only honest then. */
const loaded = ref(false)
const hasMore = computed(() => items.value.length < total.value)

/** The fields a typed term is matched against; the endpoint ANDs its filters, so one request each. */
const SEARCH_FIELDS = ['name', 'city', 'postcode']

/**
 * The REST query the way Luma's resource-url-manager writes it
 */
function searchUrl(currentPage: number, field?: string): string {
    const params = new URLSearchParams()
    params.set('searchRequest[scopeCode]', props.config.website_code ?? '')
    params.set('searchRequest[pageSize]', String(props.config.page_size ?? 10))
    params.set('searchRequest[currentPage]', String(currentPage))
    ;(props.config.skus ?? []).forEach((sku, i) => {
        params.set(`searchRequest[extensionAttributes][productsInfo][${i}][sku]`, sku)
    })
    if (field) {
        params.set(`searchRequest[filters][${field}][value]`, `%${query.value.trim()}%`)
        params.set(`searchRequest[filters][${field}][conditionType]`, 'like')
    }
    return `${props.config.search_url ?? ''}?${params}`
}

async function get(url: string): Promise<SearchResult> {
    const response = await fetch(url, { credentials: 'same-origin' })
    return response.ok ? await response.json() : {}
}

/**
 * Without a term, one page of every store and Show More pages on; a search shows its first page only
 */
let request = 0
async function load(nextPage: number) {
    if (!props.config.search_url) return
    const current = ++request
    loading.value = true
    try {
        const term = query.value.trim()
        let found: Location[]
        let count: number
        if (term) {
            const results = await Promise.all(SEARCH_FIELDS.map(field => get(searchUrl(1, field))))
            const byCode = new Map<string, Location>()
            for (const result of results)
                for (const item of result.items ?? []) byCode.set(item.pickup_location_code, item)
            found = [...byCode.values()]
            count = found.length
        } else {
            const result = await get(searchUrl(nextPage))
            found = nextPage === 1 ? (result.items ?? []) : [...items.value, ...(result.items ?? [])]
            count = result.total_count ?? found.length
        }
        // A later search has been sent since: its answer is the one to show.
        if (current !== request) return
        items.value = found
        total.value = count
        page.value = nextPage
    } finally {
        if (current === request) {
            loading.value = false
            loaded.value = true
        }
    }
}

// The first page when the list opens, a fresh first page on every search
let debounce: ReturnType<typeof setTimeout> | undefined
watch(
    open,
    isOpen => {
        if (isOpen && !loaded.value) void load(1)
    },
    { immediate: true }
)
watch(query, () => {
    clearTimeout(debounce)
    debounce = setTimeout(() => load(1), 300)
})

// ── Printing a store ──────────────────────────────────────────────────────────

/** City, region and postcode on one line, the way Luma prints a store. */
const cityLine = (location: Location) =>
    [location.city, [location.region, location.postcode].filter(Boolean).join(' ')].filter(Boolean).join(', ')

/** The REST endpoint sends the country code; the name comes from directory-data, as everywhere in the checkout. */
const countryName = (code?: string) =>
    checkout.countryOptions.find(option => option.value === code)?.label ?? code ?? ''

defineExpose({
    validate() {
        if (selected.value) return true
        error.value = labels.value.required ?? ''
        return false
    },
    data: () => ({ pickupLocationCode: selected.value?.pickup_location_code ?? '' }),
    summary: computed(() => selected.value?.name ?? ''),
})
</script>

<template>
    <div>
        <p class="text-sm font-semibold uppercase tracking-wide text-base-content/70 mb-2">{{ labels.pickUpAt }}</p>

        <!-- The store picked, and the way to change it. -->
        <div
            v-if="selected"
            class="flex items-start justify-between gap-4 p-3 rounded-box border-(length:--border) border-base-300 bg-base-100"
        >
            <div class="text-sm text-base-content/70">
                <p class="text-base font-semibold text-base-content">{{ selected.name }}</p>
                <p v-if="selected.street">{{ selected.street }}</p>
                <p v-if="cityLine(selected)">{{ cityLine(selected) }}</p>
                <p v-if="selected.country_id">{{ countryName(selected.country_id) }}</p>
                <a
                    v-if="selected.phone"
                    :href="'tel:' + selected.phone"
                    class="text-link no-underline hover:underline underline-offset-4"
                    >{{ selected.phone }}</a
                >
            </div>
            <button v-if="!open" type="button" :class="SECONDARY_BUTTON" class="shrink-0" @click="open = true">
                {{ labels.selectOther }}
            </button>
        </div>
        <!-- No store yet: the button alone, the list waits behind it. -->
        <button v-else-if="!open" type="button" :class="SECONDARY_BUTTON" @click="open = true">
            {{ labels.selectStore }}
        </button>

        <template v-if="open">
            <input
                v-model="query"
                type="search"
                :placeholder="labels.search"
                :aria-label="labels.search"
                :class="checkout.inputClass(false)"
                class="mt-3"
            />

            <p v-if="loading && !items.length" class="py-4 text-base text-base-content/70 text-center">
                {{ labels.loading }}
            </p>
            <p v-else-if="loaded && !items.length" class="mt-3 text-sm text-base-content/70">{{ labels.noResults }}</p>

            <div v-else class="mt-3 space-y-2">
                <div
                    v-for="location in items"
                    :key="location.pickup_location_code"
                    class="flex items-start justify-between gap-4 p-3 rounded-box border-(length:--border) transition-colors"
                    :class="
                        selected?.pickup_location_code === location.pickup_location_code
                            ? 'border-primary bg-base-100'
                            : 'border-base-300 hover:border-base-400'
                    "
                >
                    <div class="text-sm text-base-content/70">
                        <p class="text-base font-semibold text-base-content">{{ location.name }}</p>
                        <p v-if="location.street">{{ location.street }}</p>
                        <p v-if="cityLine(location)">{{ cityLine(location) }}</p>
                        <p v-if="location.country_id">{{ countryName(location.country_id) }}</p>
                        <a
                            v-if="location.phone"
                            :href="'tel:' + location.phone"
                            class="text-link no-underline hover:underline underline-offset-4"
                            >{{ location.phone }}</a
                        >
                        <!-- Admin-written, may carry markup: rendered as Luma renders it. -->
                        <div v-if="location.description" class="mt-1" v-html="location.description"></div>
                    </div>
                    <button type="button" :class="SECONDARY_BUTTON" class="shrink-0" @click="pick(location)">
                        {{ labels.selectStore }}
                    </button>
                </div>

                <button
                    v-if="hasMore"
                    type="button"
                    :disabled="loading"
                    :class="SECONDARY_BUTTON"
                    class="w-full"
                    @click="load(page + 1)"
                >
                    {{ loading ? labels.loading : labels.showMore }}
                </button>
            </div>
        </template>

        <p v-if="error" data-checkout-error class="text-sm text-error mt-2">{{ error }}</p>
    </div>
</template>
