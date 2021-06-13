if (document.getElementById("merit-admin")) {
    Vue.use(VeeValidate);
    var vm = new Vue({
        el: '#merit-admin',
        created() {
            this.newCountryObject();
            this.newCurrency();

            //make sure all payment methods exist for checkbox check
            for (const paymentMethodPaid of Object.values(this.paymentMethods)) {
                let found = false;
                for (const i in this.settings.paymentMethodsPaid) {
                    if (i === paymentMethodPaid) {
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    this.settings.paymentMethodsPaid[paymentMethodPaid] = false;
                }
            }

            miniToastr.init({
                appendTarget: document.body
            });
        },
        data() {
            return {
                settings: merit_settings.settings,
                paymentMethods: merit_settings.paymentMethods,
                syncInProgress: false
            };
        },
        methods: {
            newCountryObject() {
                this.settings.countryObjects.push({country: "", object_id: ""});
            },
            newCurrency() {
                this.settings.currencyBanks.push({currency_code: "", currency_bank: ""});
            },
            removeCountryObject(id) {
                this.settings.countryObjects.splice(id, 1);
            },
            removeCurrency(id) {
                this.settings.currencyBanks.splice(id, 1);
            },
            saveSettings() {
                axios.post(merit_settings.ajaxUrl + "?action=merit_save_settings", this.settings).then(
                    res => {
                        console.log('Settings saved', res.data.settings);

                        this.settings = res.data.settings;

                        miniToastr.success('Settings saved');
                    });
            },
            importProducts() {
                this.syncInProgress = true;
                console.log('Sync started');
                miniToastr.success('Sync started');
                axios.get(merit_settings.ajaxUrl + "?action=merit_sync_products").then(
                    res => {
                        console.log('Sync running in background');
                        miniToastr.success('Sync running in background');
                        this.syncInProgress = false;
                    }).catch(_ => {
                    miniToastr.error('Sync starting failed');
                    this.syncInProgress = false;
                });
            }
        },
        computed: {
            formValid() {
                return this.formFieldsValidated && this.hasApiKey && this.hasApiSecret && this.hasDefaultPayment;
            },
            formFieldsValidated() {
                return this.errors.items.length <= 0;
            },
            hasApiKey() {
                return typeof this.settings.apiKey == "string" && this.settings.apiKey.length > 0;
            },
            hasApiSecret() {
                return typeof this.settings.apiSecret == "string" && this.settings.apiSecret.length > 0;
            },
            hasDefaultPayment() {
                return typeof this.settings.defaultPayment == "string" && this.settings.defaultPayment.length > 0;
            }
        }

    })
}
