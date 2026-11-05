import SwiftUI

struct FloatingNavItem {
    let icon: String
    let label: String
    let destination: AnyView
}

struct FloatingNav: View {
    let mainIcon: String
    let items: [FloatingNavItem]
    
    @State private var isOpen = false
    @State private var navigateToIndex: Int? = nil
    
    var body: some View {
        ZStack {
          
            ForEach(Array(items.enumerated()), id: \.offset) { index, item in
                NavigationLink(
                    destination: item.destination,
                    tag: index,
                    selection: $navigateToIndex
                ) {
                    EmptyView()
                }
            }
            
          
            VStack {
                Spacer()
                HStack {
                    Spacer()
                    VStack(spacing: 15) {
                       
                        ForEach(Array(items.enumerated()), id: \.offset) { index, item in
                            if isOpen {
                                Button(action: {
                                    navigateToIndex = index
                                    withAnimation { isOpen = false }
                                }) {
                                    Image(systemName: item.icon)
                                        .resizable()
                                        .scaledToFit()
                                        .frame(width: 25, height: 25)
                                        .foregroundColor(.white)
                                        .padding()
                                        .background(AppColors.dark)
                                        .clipShape(Circle())
                                        .shadow(radius: 3)
                                }
                                .transition(.move(edge: .trailing).combined(with: .opacity))
                            }
                        }
                        
                   
                        Button(action: {
                            withAnimation(.spring()) { isOpen.toggle() }
                        }) {
                            Image(systemName: mainIcon)
                                .resizable()
                                .scaledToFit()
                                .frame(width: 30, height: 30)
                                .foregroundColor(.white)
                                .padding()
                                .background(AppColors.primary)
                                .clipShape(Circle())
                                .shadow(radius: 4)
                        }
                    }
                    .padding(.trailing, 20)
                }
                Spacer()
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .allowsHitTesting(true) 
        .zIndex(999)
    }
}
