<template>
    <div class="system-dashboard">
        <header class="system-page-heading">
            <div>
                <h1>Contabilidad</h1>
                <p>Exportación de comprobantes por empresa y periodo</p>
            </div>
        </header>

        <section class="system-panel">
            <div class="system-toolbar">
                <div class="system-filter">
                    <label id="acc-month-label">Mes</label>
                    <el-select aria-labelledby="acc-month-label" v-model="month">
                        <el-option v-for="(label, index) in months" :key="index" :value="index + 1" :label="label"></el-option>
                    </el-select>
                </div>
                <div class="system-filter">
                    <label id="acc-year-label">Año</label>
                    <el-select aria-labelledby="acc-year-label" v-model="year">
                        <el-option v-for="option in years" :key="option" :value="option" :label="String(option)"></el-option>
                    </el-select>
                </div>
                <div class="system-filter">
                    <label id="acc-type-label">Exportar a</label>
                    <el-select aria-labelledby="acc-type-label" v-model="type">
                        <el-option v-for="option in types" :key="option.value" :value="option.value" :label="option.label"></el-option>
                    </el-select>
                </div>
                <div class="system-filter system-filter-grow">
                    <label for="acc-search">Buscar empresa</label>
                    <div class="system-input-icon">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon('search')" /></svg>
                        <input id="acc-search" type="search" v-model="search" placeholder="Nombre, dominio o RUC" />
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="system-client-table">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>RUC</th>
                            <th>Correo</th>
                            <th class="text-right">Exportación · {{ periodLabel }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filteredRecords" :key="row.id">
                            <td><strong>{{ row.name }}</strong><small>{{ row.hostname }}</small></td>
                            <td class="system-mono">{{ row.number }}</td>
                            <td>{{ row.email }}</td>
                            <td class="text-right">
                                <button type="button" class="system-button system-button-small" :aria-label="'Exportar ' + row.name" @click.prevent="clickDownload(row.id)">
                                    <svg class="sys-icon" aria-hidden="true"><use :href="$icon('file-spreadsheet')" /></svg>Exportar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="loadError" class="system-table-empty" role="alert">No se pudieron cargar las empresas. <button type="button" class="system-link-button" @click="getData()">Reintentar</button></p>
            <p v-else-if="loading" class="system-table-empty" role="status">Cargando empresas…</p>
            <p v-else-if="!filteredRecords.length" class="system-table-empty">{{ records.length ? 'Ninguna empresa coincide con la búsqueda.' : 'Todavía no hay empresas registradas.' }}</p>
        </section>
    </div>
</template>

<script>
    import queryString from 'query-string'

    export default {
        data() {
            const now = new Date()
            return {
                resource: 'accounting',
                records: [],
                loading: true,
                loadError: false,
                search: '',
                month: now.getMonth() + 1,
                year: now.getFullYear(),
                type: 'concar',
                months: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'],
                types: [
                    {value: 'concar', label: 'CONCAR'},
                    {value: 'siscont', label: 'SISCONT'},
                    {value: 'foxcont', label: 'FOXCONT'},
                    {value: 'contasis', label: 'CONTASIS'}
                ]
            }
        },
        computed: {
            years() {
                const current = new Date().getFullYear()
                return [0, 1, 2, 3, 4].map(offset => current - offset)
            },
            period() {
                return `${this.year}-${String(this.month).padStart(2, '0')}`
            },
            periodLabel() {
                return `${this.months[this.month - 1]} ${this.year}`
            },
            filteredRecords() {
                const query = this.search.trim().toLocaleLowerCase()
                return this.records.filter(row =>
                    [row.name, row.number, row.hostname].some(value =>
                        String(value || '').toLocaleLowerCase().includes(query)
                    )
                )
            }
        },
        created() {
            this.getData()
        },
        methods: {
            getData() {
                this.loading = true
                this.loadError = false
                return this.$http.get(`/${this.resource}/records`)
                    .then(response => {
                        this.records = response.data.data
                    })
                    .catch(() => {
                        this.loadError = true
                    })
                    .then(() => {
                        this.loading = false
                    })
            },
            clickDownload(id) {
                const query = queryString.stringify({id, month: this.period, type: this.type})
                window.open(`/${this.resource}/download?${query}`, '_blank')
            }
        }
    }
</script>
