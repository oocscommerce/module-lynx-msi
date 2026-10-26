import { mountNode } from '@oocs/module-lynx-theme/test'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import StorePickup from 'OOCS_LynxMSI/Components/StorePickup.vue'
import { useCustomerDataStore } from 'OOCS_LynxTheme/stores/global/customerDataStore'
import { describe, expect, it, vi } from 'vitest'

const SEARCH_URL = 'https://shop.test/rest/default/V1/inventory/in-store-pickup/pickup-locations/'

// Model\PickupLocations::getConfig()
const config = {
    search_url: SEARCH_URL,
    website_code: 'base',
    skus: ['24-MB01', 'MH01-XS-Black'],
    page_size: 2,
    selected: null,
    labels: {
        pickUpAt: 'Pick up at',
        selectStore: 'Select Store',
        selectOther: 'Select Other',
        search: 'Search by store name, city or postcode',
        loading: 'Loading...',
        noResults: 'We were unable to find nearby locations for provided search query.',
        showMore: 'Show more',
        required: 'Please select a store to pick up your order.',
    },
}

const austin = {
    pickup_location_code: 'austin',
    name: 'Austin Store',
    street: '100 Congress Ave',
    city: 'Austin',
    region: 'Texas',
    postcode: '78701',
    country_id: 'US',
    phone: '+1 512 555 0100',
    description: '<p>Open <b>9 to 5</b></p>',
}
const dallas = { pickup_location_code: 'dallas', name: 'Dallas Store', city: 'Dallas', country_id: 'US' }
const berlin = {
    pickup_location_code: 'berlin',
    name: 'Berlin Store',
    city: 'Berlin',
    postcode: '10115',
    country_id: 'DE',
}

/** fetch answering each search request with what `answer` gives for its query */
const stubFetch = (answer: (params: URLSearchParams) => unknown) => {
    const fetch = vi.fn(async (url: string) => ({ ok: true, json: async () => answer(new URL(url).searchParams) }))
    vi.stubGlobal('fetch', fetch)
    return fetch
}

type Renderer = { validate: () => boolean; data: () => { pickupLocationCode: string }; summary: string }

const mount = async (selected: typeof austin | null = null) => {
    const wrapper = mountNode(StorePickup, { props: { config: { ...config, selected } } })
    useCustomerDataStore().sections = { 'directory-data': { US: { name: 'United States' }, DE: { name: 'Germany' } } }
    await flushPromises()
    return { wrapper, renderer: wrapper.findComponent(StorePickup).vm as unknown as Renderer }
}

const button = (wrapper: VueWrapper, text: string) => wrapper.findAll('button').filter(b => b.text() === text)

describe('StorePickup', () => {
    it('waits for the visitor to ask for the store list', async () => {
        const fetch = stubFetch(() => ({}))
        const { wrapper } = await mount()

        expect(wrapper.text()).toContain('Pick up at')
        expect(button(wrapper, 'Select Store')).toHaveLength(1)
        expect(wrapper.find('input[type="search"]').exists()).toBe(false)
        expect(fetch).not.toHaveBeenCalled()
    })

    it('lists the first page of stores for the cart when opened', async () => {
        const fetch = stubFetch(() => ({ items: [austin, dallas], total_count: 2 }))
        const { wrapper } = await mount()

        await button(wrapper, 'Select Store')[0].trigger('click')
        await flushPromises()

        const url = new URL(fetch.mock.calls[0][0])
        expect(url.origin + url.pathname).toBe(SEARCH_URL)
        expect(url.searchParams.get('searchRequest[scopeCode]')).toBe('base')
        expect(url.searchParams.get('searchRequest[pageSize]')).toBe('2')
        expect(url.searchParams.get('searchRequest[currentPage]')).toBe('1')
        expect(url.searchParams.get('searchRequest[extensionAttributes][productsInfo][1][sku]')).toBe('MH01-XS-Black')
        expect(fetch.mock.calls[0][1]).toEqual({ credentials: 'same-origin' })

        const text = wrapper.text()
        expect(text).toContain('Austin Store')
        expect(text).toContain('100 Congress Ave')
        expect(text).toContain('Austin, Texas 78701')
        expect(text).toContain('United States')
        expect(wrapper.find('a[href="tel:+1 512 555 0100"]').exists()).toBe(true)
        expect(wrapper.find('b').text()).toBe('9 to 5')
        expect(text).not.toContain('Show more')
    })

    it('picks a store, closes the list and hands the pick to the step', async () => {
        stubFetch(() => ({ items: [austin, dallas], total_count: 2 }))
        const { wrapper, renderer } = await mount()
        await button(wrapper, 'Select Store')[0].trigger('click')
        await flushPromises()

        await button(wrapper, 'Select Store')[1].trigger('click')

        expect(wrapper.find('input[type="search"]').exists()).toBe(false)
        expect(wrapper.text()).toContain('Dallas Store')
        expect(button(wrapper, 'Select Other')).toHaveLength(1)
        expect(renderer.validate()).toBe(true)
        expect(renderer.data()).toEqual({ pickupLocationCode: 'dallas' })
        expect(renderer.summary).toBe('Dallas Store')
    })

    it('blocks Continue without a store and says why', async () => {
        stubFetch(() => ({}))
        const { wrapper, renderer } = await mount()

        expect(renderer.validate()).toBe(false)
        await flushPromises()

        expect(wrapper.find('[data-checkout-error]').text()).toBe('Please select a store to pick up your order.')
        expect(renderer.data()).toEqual({ pickupLocationCode: '' })
    })

    it('keeps the store already on the quote', async () => {
        const fetch = stubFetch(() => ({}))
        const { wrapper, renderer } = await mount(austin)

        expect(wrapper.text()).toContain('Austin Store')
        expect(button(wrapper, 'Select Other')).toHaveLength(1)
        expect(renderer.validate()).toBe(true)
        expect(renderer.summary).toBe('Austin Store')
        expect(fetch).not.toHaveBeenCalled()
    })

    it('pages on with Show more', async () => {
        const fetch = stubFetch(params =>
            params.get('searchRequest[currentPage]') === '1'
                ? { items: [austin, dallas], total_count: 3 }
                : { items: [berlin], total_count: 3 }
        )
        const { wrapper } = await mount()
        await button(wrapper, 'Select Store')[0].trigger('click')
        await flushPromises()

        await button(wrapper, 'Show more')[0].trigger('click')
        await flushPromises()

        expect(new URL(fetch.mock.calls[1][0]).searchParams.get('searchRequest[currentPage]')).toBe('2')
        expect(button(wrapper, 'Select Store')).toHaveLength(3)
        expect(wrapper.text()).toContain('Berlin Store')
        expect(wrapper.text()).toContain('Germany')
        expect(button(wrapper, 'Show more')).toHaveLength(0)
    })

    it('searches name, city and postcode after a pause and merges the answers', async () => {
        vi.useFakeTimers()
        const fetch = stubFetch(params => {
            if (params.has('searchRequest[filters][name][value]')) return { items: [berlin] }
            if (params.has('searchRequest[filters][city][value]')) return { items: [berlin, austin] }
            if (params.has('searchRequest[filters][postcode][value]')) return { items: [] }
            return { items: [dallas], total_count: 1 }
        })
        const { wrapper } = await mount()
        await button(wrapper, 'Select Store')[0].trigger('click')
        await vi.advanceTimersByTimeAsync(0)

        await wrapper.find('input[type="search"]').setValue(' ber ')
        await vi.advanceTimersByTimeAsync(299)
        expect(fetch).toHaveBeenCalledTimes(1)
        await vi.advanceTimersByTimeAsync(1)
        await flushPromises()

        const searches = fetch.mock.calls.slice(1).map(([url]) => new URL(url).searchParams)
        expect(searches.map(params => [...params.keys()].find(key => key.includes('[filters]')))).toEqual([
            'searchRequest[filters][name][value]',
            'searchRequest[filters][city][value]',
            'searchRequest[filters][postcode][value]',
        ])
        expect(searches[0].get('searchRequest[filters][name][value]')).toBe('%ber%')
        expect(searches[0].get('searchRequest[filters][name][conditionType]')).toBe('like')
        expect(searches[0].get('searchRequest[currentPage]')).toBe('1')
        expect(button(wrapper, 'Select Store')).toHaveLength(2)
        expect(wrapper.text()).toContain('Berlin Store')
        expect(wrapper.text()).toContain('Austin Store')
        expect(wrapper.text()).not.toContain('Dallas Store')
    })

    it('says so when no store matches', async () => {
        stubFetch(() => ({ items: [], total_count: 0 }))
        const { wrapper } = await mount()

        await button(wrapper, 'Select Store')[0].trigger('click')
        await flushPromises()

        expect(wrapper.text()).toContain('We were unable to find nearby locations for provided search query.')
    })
})
