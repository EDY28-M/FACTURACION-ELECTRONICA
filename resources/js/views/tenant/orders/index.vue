

<template>
  <div v-loading="loading_submit">
    <div class="page-header pr-0">
      <h2>
        <a href="/dashboard">
          <i class="fas fa-tachometer-alt"></i>
        </a>
      </h2>
      <ol class="breadcrumbs">
        <li class="active">
          <span>Pedidos</span>
        </li>
      </ol>
      <div class="right-wrapper pull-right"></div>
    </div>
    <div class="card mb-0">
      <div class="card-header bg-info">
        <h3 class="my-0">Listado de Pedidos Tienda Virtual</h3>
      </div>
      <div class="card-body">
        <data-table :resource="resource">
          <tr slot="heading" width="100%">
            <th>#</th>
            <th>Codigo de Pedido</th>
            <th>Cliente</th>
            <th class="text-center">Detalle Productos</th>
            <th>Total</th>
            <th>Fecha Emision</th>
            <th>Medio Pago</th>
            <th>Estatus del Pedido</th>
            <th>Comprobante Electronico</th>
            <th>Comprobante</th>
          </tr>
          <tr></tr>
          <tr slot-scope="{ index, row }">
            <td>{{ index }}</td>
            <td>{{ row.order_id }}</td>
            <td>{{ row.customer }}</td>
            <td class="text-center">
              <template>
                <el-popover placement="right" width="540" trigger="click">
                  <el-table style="width: 100%" :data="row.items">
                    <el-table-column width="150" property="name" label="Nombre"></el-table-column>
                    <el-table-column width="90" property="cantidad" label="Cant."></el-table-column>
                    <el-table-column width="90" label="Precio">
                      <template slot-scope="scope">
                        <span>{{ (scope.row.currency_type_id === 'USD') ? '$' : 'S/' }} {{ Number( scope.row.sale_unit_price).toFixed(2)}}</span>
                      </template>
                    </el-table-column>
                    <el-table-column width="90" property="exchange_rate_sale" label="T/C"></el-table-column>
                    <el-table-column width="90" label="Subtotal">
                      <template slot-scope="scope">
                        <span>S/ {{ subtotal(scope.row) }}</span>
                      </template>
                    </el-table-column>
                  </el-table>
                  <table class="el-table--small el-table--fit el-table">
                    <thead class="has-gutter">
                      <th colspan="2" class="text-center">Contacto</th>
                    </thead>
                    <tbody>
                      <tr class="el-table tr">
                        <td class="el-table--small td">TELÉFONO: {{ row.customer_telefono }}</td>
                      </tr>
                      <tr class="el-table tr">
                        <td class="el-table--small td">DIRECCIÓN: {{ row.customer_direccion }}</td>
                      </tr>
                    </tbody>
                  </table>
                  <el-button slot="reference" icon="el-icon-zoom-in"></el-button>
                </el-popover>
              </template>
            </td>
            <td>S/ {{row.total}}</td>
            <td>{{row.created_at}}</td>
            <td>{{row.reference_payment}}</td>
            <td>
              <el-select
                v-model="row.status_order_id"
                placeholder="Estatus Pedido"
                :value="row.status_order_id"
                @change="updateStatus(row)"
              >
                <el-option
                  v-for="item in options"
                  :key="item.id"
                  :label="item.description"
                  :value="item.id"
                ></el-option>
              </el-select>
            </td>
            <td class="text-center">{{row.number_document}}</td>
            <td class="text-center">
              <el-button v-if="row.document_external_id" class="submit" type="success" icon="el-icon-tickets" @click.prevent="clickDownload(row.document_external_id)"></el-button>
            </td>
          </tr>
        </data-table>
      </div>
    </div>

    <options-form
      :showDialog.sync="showDialogOptions"
      :recordId="documentNewId"
      :statusDocument="statusDocument"
      :resource="resource_options"
    ></options-form>

    <document-form :order_id="order_id" :user="user" :document_types="document_types" ref="document_form">

    </document-form>
  </div>
</template>
<script>
import DataTable from "../../../components/DataTable.vue";
import queryString from "query-string";
import OptionsForm from "../pos/partials/options.vue";
import DocumentForm from "./partials/document_form.vue";

export default {
  props: ['user'],

  components: { DataTable, OptionsForm, DocumentForm},
  data() {
    return {
      showImportDialog: false,
      showImageDetail: false,
      resource: "orders",
      recordId: null,
      options: [],
      estableciment_id: "",
      record: "", // record orders
      showDialogOptions: false,
      documentNewId: null,
      statusDocument: {},
      resource_options: null,
      loading_submit: false,
      document_types:[],
      order_id: null

    }
  },
  async created() {
    this.$http.get(`/statusOrder/records`).then(response => {
      this.options = response.data;
    });
    this.events()
  },
  computed: {},
  methods: {
    async clickDownload(row) {
      await this.$http.get(`/documents/search/externalId/${row}`).then((response) => {
        this.documentNewId = response.data.id
      })
      this.statusDocument.send = ""
      this.resource_options = 'documents'
      this.showDialogOptions = true
    },
    subtotal(item) {
      var subtotal;
      if (item.currency_type_id === "USD") {
        subtotal = Number(
          item.cantidad *
            item.exchange_rate_sale *
            parseFloat(item.sale_unit_price)
        ).toFixed(2);
        if (isNaN(subtotal)) {
          return "-";
        } else {
          return subtotal
        }
      } else {
        return parseFloat(item.cantidad * item.sale_unit_price)
      }
    },
    async updateStatus(record) {
      this.record = record
      if (record.status_order_id === 2) {

         this.order_id =  record.id

        if(record.document_external_id)
        {
            // Already invoiced: only the status changes (the server refuses invalid transitions).
            return this.saveUpdateStatus()
        }

        // The comprobante is built by the server from the stored order; on failure the table reloads
        // so the status shown matches the database.
        await this.$refs.document_form.openOrder(record.id)
      } else {
        this.saveUpdateStatus()
      }
    },
    saveUpdateStatus(){
      return this.$http.post(`/statusOrder/update`, { record: this.record }).then(response => {
        this.$message.success(response.data.message)
        // Always show what the server stored.
        this.$eventHub.$emit('reloadData')
      }).catch(error => {
        this.$message.error(this.errorMessage(error))
        this.$eventHub.$emit('reloadData')
      })
    },
    errorMessage(error) {
      const data = error && error.response && error.response.data
      return (data && data.message) ? data.message : 'No se pudo actualizar el pedido'
    },
    async events() {
      await this.$eventHub.$on("cancelSale", () => {
        this.showDialogOptions = false
      });
    },

    getHeaderConfig() {
      let token = this.user.api_token
      let httpConfig = {
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`
        }
      }
      return httpConfig
    },

  },

}
</script>
