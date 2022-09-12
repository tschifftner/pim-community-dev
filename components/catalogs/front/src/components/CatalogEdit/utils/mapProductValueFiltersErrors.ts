import {CatalogFormErrors} from '../models/CatalogFormErrors';
import {findFirstError} from './findFirstError';
import {ProductValueFiltersErrors} from '../../ProductValueFilters';

export const mapProductValueFiltersErrors = (errors: CatalogFormErrors): ProductValueFiltersErrors => ({
    channels: findFirstError(errors, '[product_value_filters][channels]'),
    currencies: findFirstError(errors, '[product_value_filters][currencies]'),
});
