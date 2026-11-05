//
//  PaymentMethodSelector.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//

import SwiftUI


struct PaymentMethod: Identifiable {
    let id = UUID()
    let name: String
    let iconName: String
}


struct PaymentMethodButton: View {
    let method: PaymentMethod
    @Binding var selectedMethod: UUID?
    
    var body: some View {
        Button(action: {
            selectedMethod = method.id
        }) {
            ZStack {
                RoundedRectangle(cornerRadius: 10)
                    .fill(selectedMethod == method.id ? AppColors.primary : Color.gray.opacity(0.2))
                    .frame(width: 55, height: 55)
                
                Image(method.iconName)
                    .resizable()
                    .scaledToFit()
                    .frame(width: 30, height: 30)
                    .foregroundColor(selectedMethod == method.id ? .white : .black)
            }
        }
        .buttonStyle(PlainButtonStyle())
    }
}

struct MorePaymentMethodRow: View {
    let method: PaymentMethod
    @Binding var selectedMethod: UUID?
    
    var body: some View {
        Button(action: {
            selectedMethod = method.id
        }) {
            HStack(spacing: 12) {
                Image(method.iconName)
                    .resizable()
                    .scaledToFit()
                    .frame(width: 35, height: 35)
                    .foregroundColor(selectedMethod == method.id ? .white : .black)
                
                Text(method.name)
                    .foregroundColor(selectedMethod == method.id ? .white : .black)
                
                Spacer()
            }
            .padding()
            .frame(maxWidth: .infinity)
            .background(selectedMethod == method.id ? AppColors.primary : Color.gray.opacity(0.2))
            .cornerRadius(10)
        }
        .buttonStyle(PlainButtonStyle())
    }
}


struct MorePaymentMethodsSheet: View {
    let methods: [PaymentMethod]
    @Binding var selectedMethod: UUID?
    
    var body: some View {
        VStack(spacing: 20) {
            Text("More Payment Methods")
                .font(.title2)
                .bold()
            
            ForEach(methods) { method in
                MorePaymentMethodRow(method: method, selectedMethod: $selectedMethod)
            }
            
            Spacer()
        }
        .padding()
    }
}


struct PaymentMethodSelector: View {
    let title: String
    let methods: [PaymentMethod]
    
    @State private var selectedMethod: UUID?
    @State private var showMore = false
    
    init(title: String, methods: [PaymentMethod]) {
            self.title = title
            self.methods = methods
            _selectedMethod = State(initialValue: methods.first?.id)
        }
    
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text(title)
                .font(.system(size: 20, weight: .bold))
            
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 12) {
                    ForEach(displayedMethods) { method in
                        Button(action: {
                            if method.name == "More" {
                                showMore.toggle()
                            } else {
                                selectedMethod = method.id
                            }
                        }) {
                            if method.name == "More" {
                                ZStack {
                                    RoundedRectangle(cornerRadius: 10)
                                        .fill(Color.gray.opacity(0.2))
                                        .frame(width: 55, height: 55)
                                    
                                    Image(method.iconName)
                                        .resizable()
                                        .scaledToFit()
                                        .frame(width: 30, height: 30)
                                        .foregroundColor(.black)
                                }
                            } else {
                                PaymentMethodButton(method: method, selectedMethod: $selectedMethod)
                            }
                        }
                        .buttonStyle(PlainButtonStyle())
                    }
                }
            }
        }
        .sheet(isPresented: $showMore) {
            MorePaymentMethodsSheet(methods: hiddenMethods, selectedMethod: $selectedMethod)
        }
    }
    
    private var displayedMethods: [PaymentMethod] {
        if methods.count > 4 {
            var firstThree = Array(methods.prefix(3))
            firstThree.append(PaymentMethod(name: "More", iconName: "dots"))
            return firstThree
        }
        return methods
    }
    
    private var hiddenMethods: [PaymentMethod] {
        Array(methods.dropFirst(3))
    }
}

struct PaymentMethodSelector_Previews: PreviewProvider {
    static let paymentMethods = [
        PaymentMethod(name: "Apple Pay", iconName: "apple"),
        PaymentMethod(name: "Visa", iconName: "visa"),
        PaymentMethod(name: "MasterCard", iconName: "mastercard"),
        PaymentMethod(name: "PayPal", iconName: "paypal")
    ]
    
    static var previews: some View {
        PaymentMethodSelector(title: "Choose Payment Method", methods: paymentMethods)
            .padding()
            .previewLayout(.sizeThatFits)
    }
}
