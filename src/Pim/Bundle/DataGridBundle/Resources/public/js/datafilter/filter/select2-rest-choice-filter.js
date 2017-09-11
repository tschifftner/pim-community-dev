'use strict';

define(
    [
        'jquery',
        'underscore',
        'oro/translator',
        'routing',
        'oro/datafilter/text-filter',
        'pim/formatter/choices/base',
        'pim/user-context',
        'pim/template/datagrid/filter/select2-choice-filter',
        'pim/initselect2',
        'jquery.select2'
    ],
    function($, _, __, Routing, TextFilter, ChoicesFormatter, UserContext, template, initSelect2) {
        return TextFilter.extend({
            operatorChoices: [],
            choiceUrl: null,
            choiceUrlParams: {},
            emptyChoice: false,
            resultsPerPage: 20,
            popupCriteriaTemplate: _.template(template),
            events: {
                'click .AknDropdown-menuLink': '_onSelectOperator'
            },

            initialize: function(options) {
                _.extend(this.events, TextFilter.prototype.events);

                if (!_.isUndefined(options)) {
                    _.extend(this, _.pick(options, 'choiceUrl', 'choiceUrlParams', 'emptyChoice'));
                }

                if (_.isUndefined(this.emptyValue)) {
                    this.emptyValue = {
                        type: 'in',
                        value: ''
                    };
                }

                TextFilter.prototype.initialize.apply(this, arguments);
            },

            _onSelectOperator: function(e) {
                this.$el.find('.AknDropdown-menuLink')
                    .removeClass('AknDropdown-menuLink--active')
                    .removeClass('active');
                $(e.currentTarget)
                    .addClass('AknDropdown-menuLink--active')
                    .addClass('active');
                this.$el.find('.AknActionButton-highlight').html($(e.currentTarget).text());

                const value = $(e.currentTarget).find('.operator_choice').attr('data-value');
                if (_.contains(['empty', 'not empty'], value)) {
                    this._disableInput();
                } else {
                    this._enableInput();
                }
                e.preventDefault();
            },

            _getSelect2Config: function() {
                var config = {
                    multiple: true,
                    width: '290px',
                    minimumInputLength: 0
                };

                if (null !== this.choiceUrl) {
                    config.ajax = {
                        url: Routing.generate(this.choiceUrl, this.choiceUrlParams),
                        cache: true,
                        data: function(term, page) {
                                return {
                                    search: term,
                                    options: {
                                        limit: this.resultsPerPage,
                                        page: page,
                                        locale: UserContext.get('catalogLocale')
                                    }
                                };
                            }.bind(this),
                        results: function(data) {
                                data.results = ChoicesFormatter.format(data);
                                data.more    = this.resultsPerPage === data.results.length;

                                return data;
                            }.bind(this)
                    };
                }

                return config;
            },

            _writeDOMValue: function(value) {
                this.$('li .operator_choice[data-value="' + value.type + '"]').trigger('click');
                var operator = this.$('li.active .operator_choice').data('value');
                if (_.contains(['empty', 'not empty'], operator)) {
                    this._setInputValue(this.criteriaValueSelectors.value, []);
                } else {
                    this._setInputValue(this.criteriaValueSelectors.value, value.value);
                }

                return this;
            },

            _readDOMValue: function() {
                var operator = this.emptyChoice ? this.$('.active .operator_choice').data('value') : 'in';

                return {
                    value: _.contains(['empty', 'not empty'], operator) ? {} : this._getInputValue(this.criteriaValueSelectors.value),
                    type: operator
                };
            },

            _renderCriteria: function(el) {
                this.operatorChoices = {
                    'in': __('pim.grid.choice_filter.label_in_list'),
                    'empty': __('pim.grid.choice_filter.label_empty'),
                    'not empty': __('pim.grid.choice_filter.label_not_empty')
                };

                $(el).append(
                    this.popupCriteriaTemplate({
                        label: this.label,
                        operatorLabel: __('pim.grid.choice_filter.operator'),
                        updateLabel: __('Update'),
                        emptyChoice: this.emptyChoice,
                        selectedOperatorLabel: this.operatorChoices[this.emptyValue.type],
                        operatorChoices: this.operatorChoices,
                        selectedOperator: this.emptyValue.type
                    })
                );

                initSelect2.init(this.$(this.criteriaValueSelectors.value), this._getSelect2Config());

                this.$(this.criteriaValueSelectors.value).addClass('AknTextField--select2');
            },

            _onClickCriteriaSelector: function(e) {
                e.stopPropagation();
                $('body').trigger('click');
                if (!this.popupCriteriaShowed) {
                    this._showCriteria();
                    this.$(this.criteriaValueSelectors.value).select2('open');
                } else {
                    this._hideCriteria();
                }
            },

            _onReadCriteriaInputKey: function(e) {
                if (e.which == 13) {
                    this.$(this.criteriaValueSelectors.value).select2('close');
                    this._hideCriteria();
                    this.setValue(this._formatRawValue(this._readDOMValue()));
                }
            },

            _getResults: function(identifiers) {
                var results = [];
                var params  = {options: {identifiers: identifiers}};

                $.ajax({
                    url: Routing.generate(this.choiceUrl, this.choiceUrlParams) + '?' + $.param(params),
                    success: function(data) {
                        results = ChoicesFormatter.format(data);
                    },
                    async: false
                });

                return results;
            },

            _getInputValue: function(input) {
                return this.$(input).select2('val');
            },

            _setInputValue: function(input, value) {
                this.$(input).select2('data', this._getResults(value));

                return this;
            },

            _updateDOMValue: function() {
                var currentValue = this.getValue();
                var data         = this.$(this.criteriaValueSelectors.value).select2('data');
                if (0 === _.difference(currentValue.value, _.pluck(data, 'id')).length) {
                    return;
                }

                return this._writeDOMValue(currentValue);
            },

            _formatDisplayValue: function(value) {
                if (_.isEmpty(value.value)) {
                    return value;
                }

                return {
                    value: _.pluck(
                        this.$(this.criteriaValueSelectors.value).select2('data'),
                        'text'
                    ).join(', ')
                };
            },

            _getCriteriaHint: function() {
                var operator = this.$('.active .operator_choice').data('value');
                if (_.contains(['empty', 'not empty'], operator)) {
                    return this.operatorChoices[operator];
                }

                var value = (arguments.length > 0) ? this._getDisplayValue(arguments[0]) : this._getDisplayValue();

                return !_.isEmpty(value.value) ? '"' + value.value + '"': this.placeholder;
            },

            /**
             * {@inheritdoc}
             */
            _enableInput: function() {
                this.$(this.criteriaValueSelectors.value).select2(this._getSelect2Config());

                TextFilter.prototype._enableInput.apply(this, arguments);
            },

            /**
             * {@inheritdoc}
             */
            _disableInput: function() {
                this.$(this.criteriaValueSelectors.value).val('').select2('destroy');

                TextFilter.prototype._disableInput.apply(this, arguments);
            }
        });
    }
);
